<?php

namespace App\Domain\Finance\Enums;

/**
 * What kind of financial event a Journal represents. This is what a
 * TransferService uses (among other things) to guarantee a transfer between
 * the user's own accounts is never misclassified as income or expense
 * (CLAUDE.md §6).
 */
enum JournalType: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';
    case TRANSFER = 'transfer';
    case REVERSAL = 'reversal';
    case ADJUSTMENT = 'adjustment';
    case OPENING_BALANCE = 'opening_balance';
}
