<?php

namespace Modules\Users\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Users\Models\OtpCode;

class OtpService
{
    public const VERIFY_EMAIL = 'verify_email';

    public const RESET_PASSWORD = 'reset_password';

    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function send(string $email, string $purpose): void
    {
        OtpCode::where('email', $email)->where('purpose', $purpose)->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $subject = $purpose === self::VERIFY_EMAIL ? 'Verify your email' : 'Reset your password';
        $body = 'Your code is: '.$code.' (valid for '.self::TTL_MINUTES.' minutes)';

        Mail::raw($body, function ($message) use ($email, $subject) {
            $message->to($email)->subject($subject);
        });
    }

    public function verify(string $email, string $purpose, string $code): bool
    {
        $otp = OtpCode::where('email', $email)->where('purpose', $purpose)->first();

        if (! $otp) {
            return false;
        }

        if ($otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->delete();

            return false;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }

        $otp->delete();

        return true;
    }
}
