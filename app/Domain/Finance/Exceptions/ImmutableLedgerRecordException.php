<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to mutate or delete a posted
 * financial record. If you're catching this to work around it, stop —
 * use ReversalService instead (CLAUDE.md §6).
 */
class ImmutableLedgerRecordException extends RuntimeException {}
