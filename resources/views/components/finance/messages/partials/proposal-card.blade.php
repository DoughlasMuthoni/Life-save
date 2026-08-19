@php
    $shape = $proposal->transaction_type->shape();
@endphp

<div class="rounded-xl border {{ $duplicate ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200 bg-white' }} p-4">
    @if ($duplicate)
        <div class="mb-3 rounded-lg bg-amber-100 px-3 py-2 text-xs text-amber-800">
            This looks like a duplicate of a message already stored
            @if ($proposal->duplicateOfMessage)
                (pasted {{ $proposal->duplicateOfMessage->created_at->diffForHumans() }}).
            @endif
            Review before confirming.
        </div>
    @endif

    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-slate-900">{{ ucwords(str_replace('_', ' ', $proposal->transaction_type->value)) }}</span>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ ucfirst($shape->value) }}</span>
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
        <details class="shrink-0 text-xs text-slate-400">
            <summary class="cursor-pointer select-none">Raw SMS</summary>
            <p class="mt-1 max-w-xs font-mono">{{ $proposal->financialMessage->raw_text }}</p>
        </details>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <label class="block text-xs font-medium text-slate-500">Account</label>
            <select wire:model="formData.{{ $proposal->id }}.financial_account_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm">
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
                <select wire:model="formData.{{ $proposal->id }}.destination_financial_account_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm">
                    <option value="">Select&hellip;</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <div>
                <label class="block text-xs font-medium text-slate-500">Category</label>
                <select wire:model="formData.{{ $proposal->id }}.transaction_category_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm">
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
                <select wire:model="formData.{{ $proposal->id }}.fee_category_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm">
                    <option value="">Select&hellip;</option>
                    @foreach ($expenseCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="mt-4 flex gap-3">
        <button wire:click="confirmProposal({{ $proposal->id }})" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
            Confirm
        </button>
        <button wire:click="rejectProposal({{ $proposal->id }})" wire:loading.attr="disabled" wire:confirm="Reject this proposed transaction?" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
            Reject
        </button>
    </div>
</div>
