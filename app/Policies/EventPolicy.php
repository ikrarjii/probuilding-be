<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewOperations(User $user, Event $event): bool
    {
        return $user->is_active && (
            $user->hasRole('super_admin')
            || ($user->hasRole('panitia') && $user->isAssignedToEvent($event->id))
        );
    }

    public function viewStatistics(User $user, Event $event): bool
    {
        return $user->is_active && (
            $user->hasAnyRole(['super_admin', 'vendor'])
            || ($user->hasRole('panitia') && $user->isAssignedToEvent($event->id))
        );
    }
}
