<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class EventStaffAuthorizer
{
    /**
     * @param  array<int, string>  $allowedAssignedRoles
     */
    public function authorize(User $actor, string $eventId, array $allowedAssignedRoles): void
    {
        if (! $actor->is_active) {
            throw new AuthorizationException('This user account is inactive.');
        }

        if ($actor->hasRole('super_admin')) {
            return;
        }

        $isAssigned = DB::table('event_user_assignments')
            ->join('roles', 'roles.id', '=', 'event_user_assignments.role_id')
            ->where('event_user_assignments.event_id', $eventId)
            ->where('event_user_assignments.user_id', $actor->id)
            ->where('event_user_assignments.is_active', true)
            ->whereIn('roles.slug', $allowedAssignedRoles)
            ->exists();

        if (! $isAssigned) {
            throw new AuthorizationException('This user is not assigned to the event with the required role.');
        }
    }
}
