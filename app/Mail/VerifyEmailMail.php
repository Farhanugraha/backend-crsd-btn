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

    public User $user;
    public string $verificationUrl;

    public function __construct(User $user, string $verificationUrl)
    {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verifikasi Email Anda - CRSD OBBAMA',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->generateHtmlTemplate(),
        );
    }

    private function generateHtmlTemplate(): string
    {
        $userName   = htmlspecialchars($this->user->name, ENT_QUOTES, 'UTF-8');
        $verifyLink = htmlspecialchars($this->verificationUrl, ENT_QUOTES, 'UTF-8');
        $year       = date('Y');
        $initials   = strtoupper(mb_substr($this->user->name, 0, 1));

        return '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email - CRSD OBBAMA</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;padding:40px 16px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

    <!-- Card -->
    <tr>
        <td style="background-color:#ffffff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">

            <!-- Header -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="background-color:#0f766e;padding:32px 40px;">
                        <table cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="width:48px;height:48px;background-color:rgba(255,255,255,0.2);border-radius:12px;text-align:center;vertical-align:middle;">
                                    <span style="font-size:20px;font-weight:900;color:#ffffff;line-height:48px;display:block;">' . $initials . '</span>
                                </td>
                                <td style="padding-left:16px;vertical-align:middle;">
                                    <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:1.5px;">Verifikasi Email</p>
                                    <p style="margin:4px 0 0 0;font-size:20px;font-weight:700;color:#ffffff;">Halo, ' . $userName . '</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <!-- Body -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="padding:36px 40px;">

                        <!-- Intro -->
                        <p style="margin:0 0 8px 0;font-size:16px;font-weight:700;color:#0f172a;">
                            Konfirmasi alamat email Anda
                        </p>
                        <p style="margin:0 0 28px 0;font-size:14px;color:#64748b;line-height:1.7;">
                            Terima kasih telah mendaftar di <strong style="color:#0f172a;">CRSD OBBAMA</strong>.
                            Klik tombol di bawah untuk mengaktifkan akun Anda.
                        </p>

                        <!-- CTA Button -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                            <tr>
                                <td align="center" style="background-color:#0f766e;border-radius:10px;">
                                    <a href="' . $verifyLink . '"
                                       style="display:block;padding:15px 32px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;text-align:center;">
                                        Verifikasi Email Sekarang
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <!-- Divider -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
                            <tr>
                                <td style="border-top:1px solid #e2e8f0;"></td>
                            </tr>
                        </table>

                        <!-- Link Fallback -->
                        <p style="margin:0 0 8px 0;font-size:12px;color:#94a3b8;">
                            Tombol tidak berfungsi? Salin link berikut ke browser Anda:
                        </p>
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                            <tr>
                                <td style="background-color:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #0f766e;border-radius:6px;padding:12px 14px;">
                                    <p style="margin:0;font-size:11px;color:#0f766e;word-break:break-all;font-family:Courier New,Courier,monospace;line-height:1.6;">' . $verifyLink . '</p>
                                </td>
                            </tr>
                        </table>

                        <!-- Warning -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
                            <tr>
                                <td style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;">
                                    <p style="margin:0;font-size:13px;color:#92400e;">
                                        <strong>Perhatian:</strong> Link ini berlaku selama <strong>24 jam</strong>.
                                        Jika kadaluarsa, Anda perlu mendaftar ulang.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <!-- Info Note -->
                        <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">
                            Jika Anda tidak merasa mendaftar di CRSD OBBAMA, abaikan email ini.
                            Akun tidak akan aktif tanpa verifikasi.
                        </p>

                    </td>
                </tr>
            </table>

            <!-- Footer -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 40px;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td>
                                    <span style="font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;">CRSD OBBAMA</span>
                                </td>
                                <td align="right">
                                    <span style="font-size:12px;color:#cbd5e1;">&copy; ' . $year . ' Semua hak dilindungi.</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

        </td>
    </tr>

    <!-- Bottom Note -->
    <tr>
        <td align="center" style="padding-top:20px;">
            <p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.6;">
                Email ini dikirim secara otomatis, mohon tidak membalas pesan ini.
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>';
    }

    public function attachments(): array
    {
        return [];
    }
}