<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * مدة جلسة SPA: افتراضي طويل + تمديد عند النشاط (ط٫١).
 */
final class AuthSession
{
    /** ٣٠ يوماً — الافتراضي عندما لا يُضبط session_minutes على المستخدم. */
    public const DEFAULT_MINUTES = 60 * 24 * 30;

    /** ٩٠ يوماً — أقصى مدة قابلة للضبط من شاشة الموظفين. */
    public const MAX_MINUTES = 60 * 24 * 90;

    public const MIN_MINUTES = 15;

    public static function minutesFor(?User $user): int
    {
        $configured = (int) ($user?->session_minutes ?: 0);
        if ($configured < 1) {
            $configured = self::DEFAULT_MINUTES;
        }

        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $configured));
    }

    public static function touch(mixed $token, ?User $user): void
    {
        // تجاهل TransientToken / موك اختبارات Sanctum::actingAs (بلا صف حقيقي في DB).
        if (! $token instanceof PersonalAccessToken || ! $token->exists || ! $token->getKey()) {
            return;
        }

        $minutes = self::minutesFor($user);
        $next = now()->addMinutes($minutes);
        // لا تكتب في كل طلب إن كانت الصلاحية ما زالت بعيدة — تقليل ضغط DB.
        if ($token->expires_at && $token->expires_at->greaterThan(now()->addMinutes((int) floor($minutes / 2)))) {
            return;
        }

        $token->forceFill(['expires_at' => $next])->save();
    }
}
