<?php

namespace App\Domain\Ingestion\Enums;

/**
 * What kind of transaction a parser extracted. Deliberately provider-
 * agnostic (not "MpesaSendMoney") so Phase 4's other parsers can reuse it.
 */
enum ExtractedTransactionType: string
{
    case SEND_MONEY = 'send_money';
    case RECEIVE_MONEY = 'receive_money';
    case BUY_GOODS = 'buy_goods';
    case PAYBILL = 'paybill';
    case WITHDRAWAL = 'withdrawal';
    case BANK_DEBIT = 'bank_debit';
    case BANK_CREDIT = 'bank_credit';

    /**
     * The ledger "shape" this transaction type must be posted as. A cash
     * withdrawal moves money from M-Pesa to cash — both real accounts the
     * user holds — so it's a TRANSFER, never an expense, even though the
     * SMS says "withdrawn" (CLAUDE.md §6: a transfer must never be
     * miscounted as income or expense).
     */
    public function shape(): TransactionShape
    {
        return match ($this) {
            self::RECEIVE_MONEY, self::BANK_CREDIT => TransactionShape::INCOME,
            self::SEND_MONEY, self::BUY_GOODS, self::PAYBILL, self::BANK_DEBIT => TransactionShape::EXPENSE,
            self::WITHDRAWAL => TransactionShape::TRANSFER,
        };
    }
}
