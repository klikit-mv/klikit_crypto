<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;

class PositionPolicy
{
    public function view(User $user, Position $position): bool
    {
        return $position->user_id === $user->id || $user->hasPermission('trading.monitor');
    }

    public function close(User $user, Position $position): bool
    {
        return $position->user_id === $user->id;
    }
}
