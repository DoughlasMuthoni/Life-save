<?php

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Onboards a real-world account: one ledger_account (ASSET by default —
 * e.g. M-Pesa, a bank account; LIABILITY for something you owe, like a
 * Fuliza overdraft) plus the financial_accounts row carrying its
 * human-facing metadata. Kept as its own service (rather than inline in a
 * Livewire component) so account creation stays testable and consistent
 * regardless of which screen triggers it later.
 */
class FinancialAccountService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function createAccount(
        User $user,
        string $name,
        FinancialAccountProvider $provider,
        string $currency = 'KES',
        ?string $accountIdentifier = null,
        LedgerAccountType $type = LedgerAccountType::ASSET,
    ): FinancialAccount {
        return DB::transaction(function () use ($user, $name, $provider, $currency, $accountIdentifier, $type) {
            $ledgerAccount = LedgerAccount::create([
                'user_id' => $user->id,
                'name' => $name,
                'type' => $type,
                'currency' => $currency,
            ]);

            $account = FinancialAccount::create([
                'user_id' => $user->id,
                'ledger_account_id' => $ledgerAccount->id,
                'name' => $name,
                'provider' => $provider,
                'account_identifier' => $accountIdentifier,
                'currency' => $currency,
            ]);

            $this->auditLogger->record(AuditAction::FINANCIAL_ACCOUNT_CREATED, $account, [
                'name' => $name,
                'provider' => $provider->value,
                'currency' => $currency,
                'type' => $type->value,
            ]);

            return $account;
        });
    }
}
