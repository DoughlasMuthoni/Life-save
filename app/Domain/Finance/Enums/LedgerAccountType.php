<?php

namespace App\Domain\Finance\Enums;

/**
 * The five classical accounting account types. Determines an account's
 * "normal balance" side, which LedgerAccount::normalBalanceSide() derives
 * from this — never store the normal balance separately, it would just be
 * another place for it to drift out of sync with reality.
 */
enum LedgerAccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case INCOME = 'income';
    case EXPENSE = 'expense';

    public function normalBalanceSide(): LedgerEntrySide
    {
        return match ($this) {
            self::ASSET, self::EXPENSE => LedgerEntrySide::DEBIT,
            self::LIABILITY, self::EQUITY, self::INCOME => LedgerEntrySide::CREDIT,
        };
    }
}
