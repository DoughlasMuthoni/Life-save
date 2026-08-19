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
}
