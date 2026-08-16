<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'event_id' => ['sometimes', 'nullable', 'uuid', 'exists:events,id'],
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($data['event_id'] ?? null, fn ($query, $eventId) => $query->where('event_id', $eventId))
            ->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->latest('created_at')
            ->paginate($data['per_page'] ?? 50)
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_id' => $log->event_id,
                'action' => $log->action,
                'actor' => $log->actor?->only(['id', 'name', 'email']),
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $logs]);
    }
}
