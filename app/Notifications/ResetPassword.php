<?php

namespace App\Notifications;

use App\Support\Tenant;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;

class ResetPassword extends LaravelResetPassword
{
    public function toMail($notifiable)
    {
        $mail = parent::toMail($notifiable);

        if (static::$toMailCallback === null && ($sender = Tenant::mailFrom($notifiable->tenant_key ?? null))) {
            $mail->from($sender->address, $sender->name);
        }

        return $mail;
    }
}
