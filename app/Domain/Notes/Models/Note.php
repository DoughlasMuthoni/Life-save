<?php

namespace App\Domain\Notes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'title', 'body'])]
class Note extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
