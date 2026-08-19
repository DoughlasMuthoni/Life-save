<?php

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Models\BalanceObservation;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Compares an SMS-reported balance against what the ledger independently
 * calculates, and flags a mismatch — never overwrites the account's actual
 * balance (CLAUDE.md §"Balance Reconciliation"). This is how a missing or
 * duplicate transaction gets noticed rather than silently drifting.
 */
class ReconciliationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function recordObservation(
        User $user,
        FinancialAccount $account,
        int $observedBalanceMinor,
        CarbonInterface $observedAt,
        ?FinancialMessage $sourceMessage = null,
    ): BalanceObservation {
        if ($account->user_id !== $user->id) {
            throw new InvalidArgumentException('That financial account does not belong to this user.');
        }

        $calculatedBalanceMinor = $account->ledgerAccount->balanceMinorAsOf($observedAt);
        $differenceMinor = $observedBalanceMinor - $calculatedBalanceMinor;
        $status = $differenceMinor === 0 ? ReconciliationStatus::MATCHED : ReconciliationStatus::MISMATCHED;

        $observation = BalanceObservation::create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'financial_message_id' => $sourceMessage?->id,
            'observed_balance_minor' => $observedBalanceMinor,
            'calculated_balance_minor' => $calculatedBalanceMinor,
            'difference_minor' => $differenceMinor,
            'observed_at' => $observedAt,
            'reconciliation_status' => $status,
        ]);

        if ($status === ReconciliationStatus::MISMATCHED) {
            $this->auditLogger->record(AuditAction::RECONCILIATION_MISMATCH_DETECTED, $observation, [
                'financial_account_id' => $account->id,
                'observed_balance_minor' => $observedBalanceMinor,
                'calculated_balance_minor' => $calculatedBalanceMinor,
                'difference_minor' => $differenceMinor,
            ]);
        }

        return $observation;
    }

    public function resolve(User $user, BalanceObservation $observation, string $note): BalanceObservation
    {
        if ($observation->user_id !== $user->id) {
            throw new InvalidArgumentException('That balance observation does not belong to this user.');
        }

        if ($observation->reconciliation_status !== ReconciliationStatus::MISMATCHED) {
            throw new RuntimeException('Only a mismatched observation can be resolved.');
        }

        $observation->update([
            'reconciliation_status' => ReconciliationStatus::RESOLVED,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);

        $this->auditLogger->record(AuditAction::RECONCILIATION_RESOLVED, $observation, [
            'note' => $note,
        ]);

        return $observation;
    }
}
