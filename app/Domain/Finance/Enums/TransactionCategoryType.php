<?php

namespace App\Domain\Finance\Enums;

enum TransactionCategoryType: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';

    public function ledgerAccountType(): LedgerAccountType
    {
        return match ($this) {
            self::INCOME => LedgerAccountType::INCOME,
            self::EXPENSE => LedgerAccountType::EXPENSE,
        };
    }
}
