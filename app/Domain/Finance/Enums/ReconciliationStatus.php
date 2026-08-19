<?php

namespace App\Domain\Finance\Enums;

enum ReconciliationStatus: string
{
    case MATCHED = 'matched';
    case MISMATCHED = 'mismatched';
    case RESOLVED = 'resolved';
}
