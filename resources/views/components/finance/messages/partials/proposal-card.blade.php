@php
    $shape = $proposal->transaction_type->shape();
    $shapeMeta = match ($shape->value) {
        'income' => ['icon' => 'trend-up', 'color' => 'green'],
        'expense' => ['icon' => 'trend-down', 'color' => 'red'],
        'transfer' => ['icon' => 'arrow-path', 'color' => 'blue'],
        default => ['icon' => 'list', 'color' => 'slate'],
    };
    $iconBg = ['green' => 'bg-green-50 text-green-600', 'red' => 'bg-red-50 text-red-600', 'blue' => 'bg-blue-50 text-blue-600', 'slate' => 'bg-slate-100 text-slate-500'][$shapeMeta['color']];
@endphp

<div class="rounded-2xl border {{ $duplicate ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200 bg-white' }} p-4">
    @if ($duplicate)
        <div class="mb-3 flex items-start gap-2 rounded-lg bg-amber-100 px-3 py-2 text-xs text-amber-800">
            <x-icon name="warning" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
                This looks like a duplicate of a message already stored
                @if ($proposal->duplicateOfMessage)
                    (pasted {{ $proposal->duplicateOfMessage->created_at->diffForHumans() }}).
                @endif
                Review before confirming.
            </span>
        </div>
    @endif

    <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $iconBg }}">
                <x-icon :name="$shapeMeta['icon']" class="h-4.5 w-4.5" />
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-slate-900">{{ ucwords(str_replace('_', ' ', $proposal->transaction_type->value)) }}</span>
                    <x-ui.badge :color="$shapeMeta['color']">{{ ucfirst($shape->value) }}</x-ui.badge>
                </div>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ \App\Domain\Finance\Support\Money::formatMinor($proposal->amount_minor, $proposal->currency) }}</p>
                @if ($proposal->fee_minor > 0)
                    <p class="text-xs text-slate-500">+ {{ \App\Domain\Finance\Support\Money::formatMinor($proposal->fee_minor, $proposal->currency) }} fee</p>
                @endif
                @if ($proposal->counterparty)
                    <p class="mt-1 text-sm text-slate-600">{{ $proposal->counterparty }}</p>
                @endif
                <p class="text-xs text-slate-400">{{ $proposal->transaction_time->format('M j, Y \a\t g:i A') }}</p>
            </div>
        </div>
        <details class="shrink-0 text-xs text-slate-400">
            <summary class="cursor-pointer select-none">Raw SMS</summary>
            <p class="mt-1 max-w-xs font-mono">{{ $proposal->financialMessage->raw_text }}</p>
        </details>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label class="block text-xs font-medium text-slate-500">Account</label>
            <select wire:model="formData.{{ $proposal->id }}.financial_account_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">Select&hellip;</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            @error("formData.{$proposal->id}.financial_account_id") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        @if ($shape->value === 'transfer')
            <div>
                <label class="block text-xs font-medium text-slate-500">Destination account</label>
                <select wire:model="formData.{{ $proposal->id }}.destination_financial_account_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Select&hellip;</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <div>
                <label class="block text-xs font-medium text-slate-500">Category</label>
                <select wire:model="formData.{{ $proposal->id }}.transaction_category_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Select&hellip;</option>
                    @foreach (($shape->value === 'income' ? $incomeCategories : $expenseCategories) as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($proposal->fee_minor > 0)
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-slate-500">Fee category</label>
                <select wire:model="formData.{{ $proposal->id }}.fee_category_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Select&hellip;</option>
                    @foreach ($expenseCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="mt-4 flex gap-3">
        <x-ui.button wire:click="confirmProposal({{ $proposal->id }})" wire:loading.attr="disabled" variant="primary" size="sm">
            <x-icon name="check" class="h-3.5 w-3.5" /> Confirm
        </x-ui.button>
        <x-ui.button wire:click="rejectProposal({{ $proposal->id }})" wire:loading.attr="disabled" wire:confirm="Reject this proposed transaction?" variant="danger" size="sm">
            <x-icon name="x-mark" class="h-3.5 w-3.5" /> Reject
        </x-ui.button>
    </div>
</div>
