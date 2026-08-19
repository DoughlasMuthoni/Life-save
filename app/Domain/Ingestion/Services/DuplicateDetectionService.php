<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Models\User;

/**
 * Two layers of hard duplicate detection (CLAUDE.md §"Duplicate Detection"):
 *
 *   1. Same provider + external transaction id for this user — the
 *      strongest signal (e.g. the same M-Pesa code pasted twice).
 *   2. Same normalized-text hash for this user — catches an identical
 *      re-paste even when no transaction code was extracted.
 *
 * Fingerprint-based fuzzy matching (priority 3 — same amount/date/
 * counterparty without an exact text or id match) is NOT implemented yet;
 * flagged as a known gap rather than silently skipped. Nothing here ever
 * deletes or merges a message — a match only ever flags, per that same
 * section of CLAUDE.md.
 */
class DuplicateDetectionService
{
    public function findDuplicate(User $user, MessageProvider $provider, ?string $externalTransactionId, string $messageHash): ?FinancialMessage
    {
        if ($externalTransactionId !== null) {
            $match = FinancialMessage::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->where('external_transaction_id', $externalTransactionId)
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        return FinancialMessage::query()
            ->where('user_id', $user->id)
            ->where('message_hash', $messageHash)
            ->first();
    }
}
