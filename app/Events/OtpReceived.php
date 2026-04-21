<?php

namespace App\Events;

use App\Models\NumberOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an OTP / SMS is received for a number order.
 * Broadcasts immediately (ShouldBroadcastNow) so the client
 * gets the update without waiting for a queue worker.
 */
class OtpReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public NumberOrder $order)
    {
    }

    /**
     * Broadcast on the private channel for this specific order.
     * The frontend subscribes to `private-orders.{id}`.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.{$this->order->id}"),
        ];
    }

    /**
     * The event name sent to the client.
     */
    public function broadcastAs(): string
    {
        return 'otp.received';
    }

    /**
     * The data sent to the client.
     */
    public function broadcastWith(): array
    {
        return [
            'order_id'     => $this->order->id,
            'otp_code'     => $this->order->otp_code,
            'status'       => $this->order->status,
            'sms_from'     => $this->order->sms_from,
            'phone_number' => $this->order->phone_number,
            'completed_at' => $this->order->completed_at?->toISOString(),
        ];
    }
}
