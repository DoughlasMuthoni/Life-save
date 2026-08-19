<?php

namespace App\Domain\Ingestion\Enums;

enum TransactionShape: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';
    case TRANSFER = 'transfer';
}
