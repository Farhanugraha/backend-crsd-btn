<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot()
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $resetUrl = config('auth.frontend_reset_password_url');
            $email = urlencode($user->email);
            
            return "{$resetUrl}?token={$token}&email={$email}";
        });
    }
}