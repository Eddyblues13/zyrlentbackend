<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPassword extends Notification
{
    public string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset Your Zyrlent Password')
            ->greeting('Hello!')
            ->line('You requested a password reset for your Zyrlent account.')
            ->action('Reset Password', $this->url)
            ->line('This link expires in 60 minutes.')
            ->line('If you did not request this, no action is needed.');
    }
}
