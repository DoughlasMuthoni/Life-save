<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ledger_account_id', 'name', 'provider', 'account_identifier', 'currency', 'is_active'])]
class FinancialAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => FinancialAccountProvider::class,
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * Convenience passthrough — the real calculation lives on LedgerAccount
     * so there is exactly one place balances are derived.
     */
    public function balanceMinor(): int
    {
        return $this->ledgerAccount->balanceMinor();
    }
}
