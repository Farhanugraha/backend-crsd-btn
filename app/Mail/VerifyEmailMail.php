<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $verificationUrl;

    public function __construct(User $user, string $verificationUrl)
    {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verifikasi Email Anda - CRSD BTN FOODER',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: $this->generateHtmlTemplate(),
        );
    }

    private function generateHtmlTemplate(): string
    {
        $userName = $this->user->name;
        $verifyLink = $this->verificationUrl;
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email - CRSD BTN FOODER</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            padding: 40px 20px;
            text-align: center;
            color: white;
        }
        .header h1 {
            font-size: 28px;
            margin: 0 0 10px 0;
            font-weight: 700;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1f2937;
        }
        .message {
            color: #4b5563;
            font-size: 15px;
            line-height: 1.8;
            margin-bottom: 30px;
        }
        .button-container {
            text-align: center;
            margin: 35px 0;
        }
        .verify-button {
            display: inline-block;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 14px 40px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
        }
        .link-text {
            background-color: #f3f4f6;
            border-left: 4px solid #10b981;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            word-break: break-all;
            font-size: 13px;
            color: #1f2937;
            font-family: monospace;
        }
        .warning {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 25px 0;
            font-size: 14px;
            color: #92400e;
            border-radius: 4px;
        }
        .footer {
            background-color: #f9fafb;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🍔 CRSD BTN FOODER</h1>
            <p>Verifikasi Email Anda</p>
        </div>

        <div class="content">
            <div class="greeting">Halo {$userName},</div>

            <div class="message">
                <p>Terima kasih telah mendaftar di <strong>CRSD BTN FOODER</strong>! 🎉</p>
                <p>Untuk menyelesaikan proses pendaftaran, silakan verifikasi email Anda dengan mengklik tombol di bawah:</p>
            </div>

            <div class="button-container">
                <a href="{$verifyLink}" class="verify-button">Verifikasi Email</a>
            </div>

            <div class="message">
                <p>Atau salin dan paste link berikut:</p>
            </div>

            <div class="link-text">{$verifyLink}</div>

            <div class="warning">
                <strong>⏰ Penting!</strong> Link berlaku selama <strong>24 jam</strong>
            </div>

            <div class="message">
                <p>Jika Anda tidak melakukan pendaftaran ini, abaikan email ini.</p>
            </div>
        </div>

        <div class="footer">
            <p>© {$year} CRSD BTN FOODER. Semua hak dilindungi.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    public function attachments(): array
    {
        return [];
    }
}