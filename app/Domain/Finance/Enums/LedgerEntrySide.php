<?php

namespace App\Domain\Finance\Enums;

enum LedgerEntrySide: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public function opposite(): self
    {
        return $this === self::DEBIT ? self::CREDIT : self::DEBIT;
    }
}
