<?php

namespace App\Modules\Auth\Services\Channels;

use App\Modules\Auth\Notifications\EmailOtpNotification;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Support\Facades\Notification;

class EmailOtpChannel
{
    public function send(LoginIdentifier $identifier, string $code): void
    {
        $this->sendTo($identifier->value, $code);
    }

    public function sendTo(string $email, string $code): void
    {
        Notification::route('mail', $email)
            ->notify(new EmailOtpNotification($code));
    }
}
