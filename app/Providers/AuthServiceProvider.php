<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use App\Listeners\SendEmailVerificationNotification;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any authentication / authorization services.
     */
    public function boot(): void
    {
        // Reset Password URL
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $resetUrl = config('auth.frontend_reset_password_url');
            $email = urlencode($user->email);
            
            return "{$resetUrl}?token={$token}&email={$email}";
        });

        // Event Listener untuk Email Verification
        $this->registerEventListeners();
    }

    /**
     * Register event listeners
     */
    private function registerEventListeners(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            Registered::class,
            SendEmailVerificationNotification::class
        );
    }
}