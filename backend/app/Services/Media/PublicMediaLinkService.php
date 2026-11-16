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
        $this->forcePublicRoot();

        return URL::temporarySignedRoute(
            'public.media.file',
            now()->addDays($days),
            ['medium' => $mediaId],
        );
    }

    /**
     * @param  list<int>  $violationIds
     */
    public function temporaryViolationsShareUrl(array $violationIds, ?int $ttlDays = null): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $violationIds), fn (int $id) => $id > 0)));
        sort($ids);
        $days = max(1, $ttlDays ?? self::DEFAULT_TTL_DAYS);
        $this->forcePublicRoot();

        return URL::temporarySignedRoute(
            'public.violations.share',
            now()->addDays($days),
            ['ids' => implode(',', $ids)],
        );
    }

    public function assertMediaExists(int $mediaId): MediaFile
    {
        return MediaFile::query()->whereKey($mediaId)->firstOrFail();
    }

    private function forcePublicRoot(): void
    {
        URL::forceRootUrl(PublicAppUrl::origin());
        $root = PublicAppUrl::origin();
        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
