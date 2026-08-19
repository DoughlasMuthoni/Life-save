<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'data', 'ip_address'])]
class AuditEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event) {
            $event->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
