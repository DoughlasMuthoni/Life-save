<?php

namespace App\Domain\Shopping\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_id', 'name', 'quantity', 'unit_price_minor', 'category'])]
class PurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function lineTotalMinor(): int
    {
        return $this->quantity * $this->unit_price_minor;
    }
}
