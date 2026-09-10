<?php

namespace App\Modules\Auth\Services\Channels;

use App\Modules\Auth\Notifications\EmailOtpNotification;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Support\Facades\Notification;

class EmailOtpChannel
{
    public function send(LoginIdentifier $identifier, string $code): void
    {
        Notification::route('mail', $identifier->value)
            ->notify(new EmailOtpNotification($code));
    }
}
