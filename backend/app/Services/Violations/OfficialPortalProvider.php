<?php

namespace App\Services\Violations;

/**
 * مزوّد تجريبي لبوابة النيابة العامة / المرور.
 * لا يستدعي السيرفر البوابة (لا API مرخّص + دخول بملكية المركبة/OTP).
 * الواجهة تستخدم الرابط الرسمي للاستعلام اليدوي ثم الحفظ/الاستيراد هنا.
 */
class OfficialPortalProvider
{
    public const PORTAL_URL = 'https://www.ppo.gov.eg/ppo/r/ppoportal/ppoportal/traffic';

    public const ALT_MOI_URL = 'https://traffic.moi.gov.eg';

    /**
     * @return array{
     *   status: string,
     *   message: string,
     *   portal_url: string,
     *   alt_url: string,
     *   results: list<array<string, mixed>>,
     *   plate_hint: string|null
     * }
     */
    public function lookup(?string $plate = null): array
    {
        return [
            'status' => 'source_unavailable',
            'message' => 'لا يوجد ربط آلي مرخّص ببوابة المخالفات. افتح البوابة الرسمية كمالك المركبة، ثم انسخ النتيجة أو استورد CSV هنا.',
            'portal_url' => self::PORTAL_URL,
            'alt_url' => self::ALT_MOI_URL,
            'results' => [],
            'plate_hint' => $plate,
        ];
    }
}
