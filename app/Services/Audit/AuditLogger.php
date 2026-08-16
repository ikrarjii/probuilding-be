<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param array<string, mixed> $metadata */
    public function record(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        ?string $eventId = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'event_id' => $eventId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : 'system',
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
