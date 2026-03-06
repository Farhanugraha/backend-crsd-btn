<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = config('auth.frontend_reset_password_url');
            $email = urlencode($user->email);
            return "{$frontendUrl}?token={$token}&email={$email}";
        });

        ResetPassword::toMailUsing(function ($notifiable, $token) {
            $resetUrl = config('auth.frontend_reset_password_url')
                . '?token=' . $token
                . '&email=' . urlencode($notifiable->email);

            return (new MailMessage)
                ->subject('Reset Password Akun CRSD OBBAMA')
                ->greeting('Halo 👋')
                ->line('Kami menerima permintaan untuk mereset password akun Anda.')
                ->action('Reset Password', $resetUrl)
                ->line('Link ini berlaku selama 60 menit.')
                ->line('Jika Anda tidak merasa melakukan permintaan ini, abaikan email ini.')
                ->salutation('Salam, Tim CRSD OBBAMA');
        });
    }
}