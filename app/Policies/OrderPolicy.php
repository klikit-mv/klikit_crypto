<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->hasPermission('trading.monitor');
    }

    /** Only the owner may cancel; staff can halt trading platform-wide but not act on behalf of users. */
    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}
