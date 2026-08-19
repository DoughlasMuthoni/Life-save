<?php

namespace App\Domain\Finance\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a proposed set of ledger entries does not balance (total
 * debits != total credits) for some currency. LedgerService must never post
 * a journal that would trigger this — it's the last line of defense against
 * a broken invariant reaching the database (CLAUDE.md §6).
 */
class UnbalancedJournalException extends InvalidArgumentException {}
