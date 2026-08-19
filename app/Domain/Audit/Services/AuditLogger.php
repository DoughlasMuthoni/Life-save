<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * The single place important actions get written to the audit trail
 * (CLAUDE.md §13). Callers pass a closed-set AuditAction, never a raw
 * string, and `data` must never contain passwords, API keys, or other
 * secrets — this class does not attempt to scrub input, so that
 * responsibility stays with the caller.
 */
class AuditLogger
{
    public function record(AuditAction $action, ?Model $subject = null, array $data = []): AuditEvent
    {
        return AuditEvent::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'data' => $data,
            'ip_address' => Request::ip(),
        ]);
    }
}
