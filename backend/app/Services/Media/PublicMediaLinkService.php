<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use App\Support\PublicAppUrl;
use Illuminate\Support\Facades\URL;

/**
 * روابط ميديا مؤقّتة موقّعة — تُفتح بلا تسجيل دخول (واتساب للعميل).
 */
class PublicMediaLinkService
{
    public const DEFAULT_TTL_DAYS = 14;

    public function mediaIdFromStored(string $stored): ?int
    {
        $value = trim($stored);
        if ($value === '') {
            return null;
        }
        if (preg_match('#/media/(\d+)/file#', $value, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('#^\d+$#', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  list<string>  $storedPaths
     * @return list<int>
     */
    public function mediaIdsFromPaths(array $storedPaths): array
    {
        $ids = [];
        foreach ($storedPaths as $path) {
            if (! is_string($path)) {
                continue;
            }
            $id = $this->mediaIdFromStored($path);
            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function temporaryFileUrl(int $mediaId, ?int $ttlDays = null): string
    {
        $days = max(1, $ttlDays ?? self::DEFAULT_TTL_DAYS);

        // توقيع نسبي (المسار فقط) حتى يعمل الرابط على دومين الإنتاج حتى لو اختلف Host المحلي.
        $relative = URL::temporarySignedRoute(
            'public.media.file',
            now()->addDays($days),
            ['medium' => $mediaId],
            absolute: false,
        );

        return $this->toPublicAbsolute($relative);
    }

    /**
     * @param  list<int>  $violationIds
     */
    public function temporaryViolationsShareUrl(array $violationIds, ?int $ttlDays = null): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $violationIds), fn (int $id) => $id > 0)));
        sort($ids);
        $days = max(1, $ttlDays ?? self::DEFAULT_TTL_DAYS);

        $relative = URL::temporarySignedRoute(
            'public.violations.share',
            now()->addDays($days),
            ['ids' => implode(',', $ids)],
            absolute: false,
        );

        return $this->toPublicAbsolute($relative);
    }

    /** رابط فاتورة عقد عامة موقّعة (واتساب للعميل). */
    public function temporaryContractInvoiceShareUrl(int $contractId, ?int $ttlDays = null): string
    {
        $id = max(1, $contractId);
        $days = max(1, $ttlDays ?? self::DEFAULT_TTL_DAYS);

        $relative = URL::temporarySignedRoute(
            'public.contracts.invoice',
            now()->addDays($days),
            ['id' => $id],
            absolute: false,
        );

        return $this->toPublicAbsolute($relative);
    }

    /** رابط بطاقة عربية عامة موقّعة (واتساب — أسعار + صور). */
    public function temporaryVehicleShareUrl(int $vehicleId, ?int $ttlDays = null): string
    {
        $id = max(1, $vehicleId);
        $days = max(1, $ttlDays ?? self::DEFAULT_TTL_DAYS);

        $relative = URL::temporarySignedRoute(
            'public.vehicles.share',
            now()->addDays($days),
            ['id' => $id],
            absolute: false,
        );

        return $this->toPublicAbsolute($relative);
    }

    public function assertMediaExists(int $mediaId): MediaFile
    {
        return MediaFile::query()->whereKey($mediaId)->firstOrFail();
    }

    private function toPublicAbsolute(string $relativeUrl): string
    {
        $relativeUrl = trim($relativeUrl);
        if (str_starts_with($relativeUrl, 'http://') || str_starts_with($relativeUrl, 'https://')) {
            return $relativeUrl;
        }

        return PublicAppUrl::to(ltrim($relativeUrl, '/'));
    }
}
