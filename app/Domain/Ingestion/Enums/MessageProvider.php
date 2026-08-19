<?php

namespace App\Domain\Ingestion\Enums;

/**
 * Who a pasted SMS looks like it came from. UNKNOWN is a legitimate, common
 * outcome — it just means no deterministic parser exists yet for this text
 * (CLAUDE.md §7: unknown messages must never become financial records
 * automatically).
 */
enum MessageProvider: string
{
    case MPESA = 'mpesa';
    case MSHWARI = 'mshwari';
    case KCB_MPESA = 'kcb_mpesa';
    case BANK = 'bank';
    case UNKNOWN = 'unknown';
}
