<?php

namespace App\Domain\Audit\Enums;

/**
 * Closed set of audit actions this system currently knows how to emit
 * (CLAUDE.md §13). Add a case here deliberately whenever a new important
 * action needs auditing — don't let ad hoc strings creep into
 * AuditLogger::record() calls.
 */
enum AuditAction: string
{
    case FINANCIAL_ACCOUNT_CREATED = 'financial_account.created';
    case TRANSACTION_POSTED = 'transaction.posted';
    case TRANSACTION_REVERSED = 'transaction.reversed';
    case SMS_PARSED = 'sms.parsed';
    case SMS_DUPLICATE_DETECTED = 'sms.duplicate_detected';
    case PROPOSED_TRANSACTION_REJECTED = 'proposed_transaction.rejected';
    case AI_PARSE_ACCEPTED = 'ai.parse_accepted';
    case AI_PARSE_REJECTED = 'ai.parse_rejected';
}
