<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ledger_account_id', 'name', 'type', 'icon', 'color', 'is_active'])]
class TransactionCategory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => TransactionCategoryType::class,
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
}
