<?php

namespace App\Listeners;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendEmailVerificationNotification
{
    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->user;

        if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !$user->hasVerifiedEmail()) {
            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addHours(24),
                [
                    'id'   => $user->id,
                    'hash' => sha1($user->email),
                ]
            );

            Mail::to($user->email)->queue(new VerifyEmailMail($user, $verificationUrl));
        }
    }
}