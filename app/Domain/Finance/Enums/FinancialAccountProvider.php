<?php

namespace App\Domain\Finance\Enums;

enum FinancialAccountProvider: string
{
    case MPESA = 'mpesa';
    case MSHWARI = 'mshwari';
    case KCB_MPESA = 'kcb_mpesa';
    case BANK = 'bank';
    case CASH = 'cash';
    case FULIZA = 'fuliza';
    case OTHER = 'other';
}
