<?php

use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Support\Money;
use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Services\GoalService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Domain\Support\Enums\Priority;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $title = '';

    public string $targetAmount = '';

    public string $monthlyContribution = '';

    public string $priority = 'medium';

    public string $targetDate = '';

    public ?int $allocatingGoalId = null;

    public string $allocateAccountId = '';

    public string $allocateAmount = '';

    public function getGoalsProperty()
    {
        return Goal::query()
            ->where('user_id', auth()->id())
            ->where('status', GoalStatus::ACTIVE)
            ->orderByDesc('priority')
            ->get();
    }

    public function getAccountsProperty()
    {
        return FinancialAccount::query()->where('user_id', auth()->id())->where('is_active', true)->get();
    }

    /**
     * The "real vs virtual" table (CLAUDE.md §SAVINGS): what's actually in
     * each account versus what's earmarked against it.
     */
    public function getAllocationBreakdownProperty(SavingsAllocationService $allocations)
    {
        return $this->accounts->map(function (FinancialAccount $account) use ($allocations) {
            $available = $account->balanceMinor();
            $allocated = $allocations->totalAllocatedForAccount($account);

            return [
                'account' => $account,
                'available' => $available,
                'allocated' => $allocated,
                'unallocated' => $available - $allocated,
            ];
        });
    }

    public function create(GoalService $goals): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'targetAmount' => ['required', 'string'],
            'monthlyContribution' => ['nullable', 'string'],
            'priority' => ['required', 'string', 'in:low,medium,high'],
            'targetDate' => ['nullable', 'date'],
        ]);

        $goals->createSavingsGoal(
            user: auth()->user(),
            title: $this->title,
            targetValueMinor: Money::toMinorUnits($this->targetAmount),
            priority: Priority::from($this->priority),
            monthlyContributionMinor: $this->monthlyContribution !== '' ? Money::toMinorUnits($this->monthlyContribution) : null,
            targetDate: $this->targetDate !== '' ? \Carbon\Carbon::parse($this->targetDate) : null,
        );

        $this->reset(['title', 'targetAmount', 'monthlyContribution', 'targetDate']);
        $this->priority = 'medium';
        $this->showForm = false;
    }

    public function startAllocating(int $goalId): void
    {
        $this->allocatingGoalId = $goalId;
        $this->allocateAccountId = '';
        $this->allocateAmount = '';
    }

    public function cancelAllocating(): void
    {
        $this->allocatingGoalId = null;
    }

    public function confirmAllocate(SavingsAllocationService $allocations): void
    {
        $this->validate([
            'allocateAccountId' => ['required', 'integer'],
            'allocateAmount' => ['required', 'string'],
        ]);

        $goal = Goal::where('user_id', auth()->id())->findOrFail($this->allocatingGoalId);
        $account = $this->accounts->firstWhere('id', (int) $this->allocateAccountId);

        if (! $account) {
            throw \Illuminate\Validation\ValidationException::withMessages(['allocateAccountId' => 'Invalid account.']);
        }

        try {
            $allocations->allocate(auth()->user(), $goal, $account, Money::toMinorUnits($this->allocateAmount));
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['allocateAmount' => $e->getMessage()]);
        }

        $this->allocatingGoalId = null;
        session()->flash('status', 'Allocation saved.');
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Savings Goals</h1>
            <p class="mt-1 text-sm text-slate-500">Virtual allocations of money you already have &mdash; never counted as extra.</p>
        </div>
        <button wire:click="$set('showForm', true)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            + New goal
        </button>
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Title</label>
                    <input wire:model="title" type="text" placeholder="e.g. Emergency Fund" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Target amount (KES)</label>
                    <input wire:model="targetAmount" type="text" inputmode="decimal" placeholder="100000.00" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('targetAmount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Planned monthly contribution <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="monthlyContribution" type="text" inputmode="decimal" placeholder="4000.00" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Priority</label>
                    <select wire:model="priority" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Target date <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="targetDate" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save goal</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($this->goals as $goal)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <p class="font-medium text-slate-900">{{ $goal->title }}</p>
                        <div class="mt-2 flex items-center gap-3">
                            <div class="h-2 flex-1 rounded-full bg-slate-100">
                                <div class="h-2 rounded-full bg-blue-600" style="width: {{ $goal->progressPercent() }}%"></div>
                            </div>
                            <span class="shrink-0 text-sm font-medium text-slate-600">{{ $goal->progressPercent() }}%</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-500">
                            {{ Money::formatMinor($goal->allocatedAmountMinor()) }} of {{ Money::formatMinor($goal->target_value) }}
                            &middot; {{ Money::formatMinor($goal->remainingAmountMinor()) }} remaining
                            @if ($goal->monthsRemaining() !== null)
                                &middot; ~{{ $goal->monthsRemaining() }} {{ Str::plural('month', $goal->monthsRemaining()) }} at current plan
                            @endif
                        </p>
                    </div>
                    <button wire:click="startAllocating({{ $goal->id }})" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Allocate
                    </button>
                </div>

                @if ($allocatingGoalId === $goal->id)
                    <form wire:submit="confirmAllocate" class="mt-4 rounded-lg bg-slate-50 p-4">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-medium text-slate-500">From account</label>
                                <select wire:model="allocateAccountId" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm">
                                    <option value="">Select&hellip;</option>
                                    @foreach ($this->accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                                @error('allocateAccountId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Amount (KES)</label>
                                <input wire:model="allocateAmount" type="text" inputmode="decimal" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm">
                                @error('allocateAmount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 flex gap-3">
                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">Confirm</button>
                            <button type="button" wire:click="cancelAllocating" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center text-sm text-slate-500">
                No savings goals yet.
            </p>
        @endforelse
    </div>

    <div class="mt-8">
        <h2 class="text-sm font-semibold text-slate-700">Allocation breakdown by account</h2>
        <p class="mt-1 text-xs text-slate-400">Real balances vs. virtual allocations. A negative unallocated figure means an account is over-committed.</p>
        <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase text-slate-500">
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Available (real)</th>
                        <th class="px-4 py-3">Allocated (virtual)</th>
                        <th class="px-4 py-3">Unallocated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($this->allocationBreakdown as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $row['account']->name }}</td>
                            <td class="px-4 py-3">{{ Money::formatMinor($row['available']) }}</td>
                            <td class="px-4 py-3">{{ Money::formatMinor($row['allocated']) }}</td>
                            <td class="px-4 py-3 {{ $row['unallocated'] < 0 ? 'font-medium text-red-600' : 'text-slate-700' }}">
                                {{ Money::formatMinor($row['unallocated']) }}
                                @if ($row['unallocated'] < 0)
                                    <span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">Over-allocated</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
