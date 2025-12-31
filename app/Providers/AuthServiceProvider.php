<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */

    public function boot()
{
    ResetPassword::createUrlUsing(function ($user, string $token) {
        return config('app.frontend_reset_password_url')
            . "?token={$token}&email={$user->email}";
    });
}
   
}
