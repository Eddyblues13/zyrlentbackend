<?php

use App\Models\NumberOrder;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Private channel for a specific number order.
 * Only the order owner can subscribe.
 */
Broadcast::channel('orders.{orderId}', function ($user, $orderId) {
    $order = NumberOrder::find($orderId);
    return $order && (int) $order->user_id === (int) $user->id;
});

