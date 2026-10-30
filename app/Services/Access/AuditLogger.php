<?php

namespace App\Services\Access;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    public function log(string $action, ?User $actor = null, ?Model $subject = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context ?: null,
            'ip_address' => $this->request?->ip(),
            'user_agent' => substr((string) $this->request?->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}
