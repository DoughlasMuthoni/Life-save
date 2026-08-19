<?php

namespace App\Domain\Shopping\Services;

use App\Domain\Shopping\Models\Merchant;
use App\Models\User;

class MerchantService
{
    /**
     * Idempotent by name — pasting/typing the same merchant twice reuses
     * the existing row instead of creating a duplicate.
     */
    public function findOrCreate(User $user, string $name, ?string $category = null): Merchant
    {
        $merchant = Merchant::query()->where('user_id', $user->id)->where('name', $name)->first();

        if ($merchant !== null) {
            return $merchant;
        }

        return Merchant::create([
            'user_id' => $user->id,
            'name' => $name,
            'category' => $category,
        ]);
    }
}
