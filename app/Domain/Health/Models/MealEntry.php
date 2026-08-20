<?php

namespace App\Domain\Health\Models;

use App\Domain\Health\Enums\MealType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'eaten_at', 'meal_type', 'description'])]
class MealEntry extends Model
{
    protected function casts(): array
    {
        return [
            'eaten_at' => 'datetime',
            'meal_type' => MealType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
