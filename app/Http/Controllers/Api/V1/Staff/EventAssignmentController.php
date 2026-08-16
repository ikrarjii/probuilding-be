<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignPanitiaRequest;
use App\Models\Event;
use App\Models\EventUserAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventAssignmentController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Event $event): JsonResponse
    {
        $assignments = $event->staffAssignments()
            ->with(['user:id,name,email,is_active', 'role:id,name,slug'])
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get()
            ->map(fn (EventUserAssignment $assignment) => $this->assignmentData($assignment));

        return response()->json(['data' => $assignments]);
    }

    public function store(AssignPanitiaRequest $request, Event $event): JsonResponse
    {
        $panitiaRole = Role::where('slug', 'panitia')->firstOrFail();
        $panitia = User::with('roles')->findOrFail($request->validated('user_id'));

        if (! $panitia->is_active || ! $panitia->hasRole('panitia')) {
            throw ValidationException::withMessages([
                'user_id' => ['Only an active user with the Panitia role can be assigned.'],
            ]);
        }

        [$assignment, $changed] = DB::transaction(function () use ($request, $event, $panitia, $panitiaRole): array {
            $assignment = EventUserAssignment::firstOrNew([
                'event_id' => $event->id,
                'user_id' => $panitia->id,
                'role_id' => $panitiaRole->id,
            ]);
            $changed = ! $assignment->exists || ! $assignment->is_active;
            $assignment->is_active = true;
            $assignment->save();

            if ($changed) {
                $this->auditLogger->record(
                    'event.panitia_assigned',
                    $request->user(),
                    $assignment,
                    $event->id,
                    ['assigned_user_id' => $panitia->id],
                );
            }

            return [$assignment, $changed];
        });

        return response()->json(
            ['data' => $this->assignmentData($assignment->load(['user', 'role']))],
            $changed ? 201 : 200,
        );
    }

    public function destroy(
        Request $request,
        Event $event,
        EventUserAssignment $assignment,
    ): JsonResponse {
        abort_unless($assignment->event_id === $event->id, 404);

        if ($assignment->is_active) {
            DB::transaction(function () use ($request, $event, $assignment): void {
                $assignment->update(['is_active' => false]);
                $this->auditLogger->record(
                    'event.panitia_unassigned',
                    $request->user(),
                    $assignment,
                    $event->id,
                    ['assigned_user_id' => $assignment->user_id],
                );
            });
        }

        return response()->json(['data' => ['unassigned' => true]]);
    }

    private function assignmentData(EventUserAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'event_id' => $assignment->event_id,
            'is_active' => $assignment->is_active,
            'user' => $assignment->user?->only(['id', 'name', 'email', 'is_active']),
            'role' => $assignment->role?->only(['id', 'name', 'slug']),
            'created_at' => $assignment->created_at?->toIso8601String(),
        ];
    }
}
