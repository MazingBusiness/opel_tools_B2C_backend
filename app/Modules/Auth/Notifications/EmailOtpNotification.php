<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) ceil(config('otp.ttl_seconds') / 60);

        return (new MailMessage)
            ->subject('Your OPEL login code')
            ->line("Your one-time code is {$this->code}.")
            ->line("It expires in {$minutes} minutes. Do not share this code.");
    }
}
