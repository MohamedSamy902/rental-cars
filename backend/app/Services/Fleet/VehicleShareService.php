<?php

namespace App\Services\Fleet;

use App\Models\MediaFile;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Services\Contracts\ContractPricingService;
use App\Services\Media\PublicMediaLinkService;
use App\Support\PublicAppUrl;

/**
 * مشاركة عربية عبر واتساب: نص تجاري معتمد + صفحة عامة موقّعة بالصور.
 */
class VehicleShareService
{
    public function __construct(
        private readonly PublicMediaLinkService $links,
    ) {}

    /**
     * @return array{share_url: string, message_text: string, expires_in_days: int, photos_count: int}
     */
    public function prepare(Vehicle $vehicle): array
    {
        $ttlDays = PublicMediaLinkService::DEFAULT_TTL_DAYS;
        $shareUrl = $this->links->temporaryVehicleShareUrl((int) $vehicle->id, $ttlDays);
        $tenant = TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];
        $center = $tenant?->center_name ?: 'شركة آر بي إم للتأجير';
        $phone = is_string($ops['print_phone'] ?? null) && trim((string) $ops['print_phone']) !== ''
            ? trim((string) $ops['print_phone'])
            : (string) config('rpm.center_public_phone', '01125253888');
        $minDays = is_numeric($ops['default_min_days'] ?? null)
            ? max(1, (int) $ops['default_min_days'])
            : (is_numeric($ops['showroom_min_rental_days'] ?? null)
                ? max(1, (int) $ops['showroom_min_rental_days'])
                : 5);

        $rates = $this->rates($vehicle);
        $photos = $this->galleryPhotos($vehicle);
        $transmission = trim((string) ($vehicle->transmission ?: ''));
        $categoryLine = $transmission !== ''
            ? "الفئة: {$vehicle->category} · {$vehicle->fuel_type} · {$transmission}"
            : "الفئة: {$vehicle->category} · {$vehicle->fuel_type}";

        $lines = [
            "{$center} — {$vehicle->brand} {$vehicle->model} {$vehicle->year}",
            $categoryLine,
            'يومي '.$this->moneyPlain($rates['daily'])." (اقل مده {$minDays}ايام)",
            '· نص شهر '.$this->moneyPlain($rates['half']),
            '· شهري '.$this->moneyPlain($rates['monthly']),
        ];
        if ($phone !== '') {
            $lines[] = "للتواصل و الاستفسار: {$phone}";
        }
        $lines[] = '';
        if ($photos->isNotEmpty()) {
            $lines[] = 'افتح التفاصيل والصور من الرابط:';
        } else {
            $lines[] = 'افتح تفاصيل العربية من الرابط:';
        }
        $lines[] = $shareUrl;
        $lines[] = 'الرابط صالح لمدة '.$ttlDays.' يوماً.';

        return [
            'share_url' => $shareUrl,
            'message_text' => implode("\n", $lines),
            'expires_in_days' => $ttlDays,
            'photos_count' => $photos->count(),
        ];
    }

    public function renderPublicHtml(Vehicle $vehicle): string
    {
        $tenant = TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];
        $center = $tenant?->center_name ?: 'شركة آر بي إم للتأجير';
        $phone = is_string($ops['print_phone'] ?? null) && trim((string) $ops['print_phone']) !== ''
            ? trim((string) $ops['print_phone'])
            : (string) config('rpm.center_public_phone', '01125253888');
        $minDays = is_numeric($ops['default_min_days'] ?? null)
            ? max(1, (int) $ops['default_min_days'])
            : (is_numeric($ops['showroom_min_rental_days'] ?? null)
                ? max(1, (int) $ops['showroom_min_rental_days'])
                : 5);

        $rates = $this->rates($vehicle);
        $photos = $this->galleryPhotos($vehicle);
        $centerEsc = e($center);
        $title = e(trim($vehicle->brand.' '.$vehicle->model.' '.$vehicle->year));
        $category = e((string) $vehicle->category);
        $fuel = e((string) $vehicle->fuel_type);
        $transmission = e(trim((string) ($vehicle->transmission ?: '—')));
        $color = e(trim((string) ($vehicle->color ?: '—')));
        $phoneEsc = e($phone);
        $daily = e($this->moneyPlain($rates['daily']));
        $half = e($this->moneyPlain($rates['half']));
        $monthly = e($this->moneyPlain($rates['monthly']));
        $minEsc = e((string) $minDays);
        $logoHtml = $this->brandLogoHtml($tenant?->logo_path);

        $heroPhoto = $photos->first();
        $heroHtml = '';
        if ($heroPhoto) {
            $src = e($this->links->temporaryFileUrl((int) $heroPhoto->id));
            $heroHtml = <<<HTML
<figure class="hero-photo">
  <img src="{$src}" alt="{$title}" loading="eager" />
</figure>
HTML;
        }

        $galleryHtml = '';
        if ($photos->count() > 1) {
            $shots = '';
            foreach ($photos->slice(1)->values() as $i => $photo) {
                $src = e($this->links->temporaryFileUrl((int) $photo->id));
                $n = $i + 2;
                $shots .= <<<HTML
<figure class="shot">
  <a href="{$src}" target="_blank" rel="noopener">
    <img src="{$src}" alt="صورة {$n}" loading="lazy" />
  </a>
</figure>
HTML;
            }
            $galleryHtml = <<<HTML
<section class="gallery" aria-label="صور إضافية">
  <h2>صور إضافية</h2>
  <div class="gallery__grid">{$shots}</div>
</section>
HTML;
        } elseif ($photos->isEmpty()) {
            $galleryHtml = '<p class="muted">لا توجد صور مرفوعة لهذه العربية.</p>';
        }

        $waHref = $phone !== ''
            ? e('https://wa.me/'.preg_replace('/\D+/', '', $phone))
            : '';
        $ctaHtml = $waHref !== ''
            ? '<a class="cta" href="'.$waHref.'" target="_blank" rel="noopener">تواصل واتساب</a>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="theme-color" content="#18181b" />
  <title>{$title} — {$centerEsc}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@500;700;800&display=swap" rel="stylesheet" />
  <style>
    :root {
      color-scheme: light;
      --ink: #18181b;
      --bg: #f4f4f5;
      --surface: #fff;
      --text: #18181b;
      --muted: #71717a;
      --accent: #dc2626;
      --border: #e4e4e7;
      --radius: 18px;
      --font-display: "Tajawal", "IBM Plex Sans Arabic", sans-serif;
      --font-body: "IBM Plex Sans Arabic", "Tajawal", sans-serif;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100dvh;
      font-family: var(--font-body);
      background:
        radial-gradient(1100px 380px at 100% -8%, color-mix(in srgb, var(--accent) 12%, transparent), transparent 55%),
        var(--bg);
      color: var(--text);
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }
    header {
      background: linear-gradient(160deg, #18181b 0%, #27272a 100%);
      color: #fff;
      padding: calc(1rem + env(safe-area-inset-top, 0px)) 1.1rem 1.35rem;
      border-end-start-radius: 28px;
      border-end-end-radius: 28px;
    }
    .brand { display: flex; align-items: center; gap: .8rem; margin-block-end: .9rem; }
    .brand__mark {
      width: 48px; height: 48px; border-radius: 14px; background: #0a0a0a;
      border: 1px solid rgb(255 255 255 / 12%); display: grid; place-items: center; overflow: hidden;
    }
    .brand__mark img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .brand__mark span { font-family: var(--font-display); font-weight: 800; color: #fff; }
    .brand__eyebrow { margin: 0; color: #a1a1aa; font-size: .78rem; }
    header h1 { margin: .15rem 0 0; font-family: var(--font-display); font-size: 1.25rem; font-weight: 800; }
    main { max-width: 720px; margin: 0 auto; padding: 1rem 1rem 0; display: grid; gap: .9rem; }
    .hero-photo {
      margin: -.35rem 0 0; border-radius: var(--radius); overflow: hidden;
      border: 1px solid var(--border); background: #e4e4e7; aspect-ratio: 16 / 10;
      box-shadow: 0 10px 28px rgb(24 24 27 / 8%);
    }
    .hero-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .card {
      background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
      padding: 1rem 1.05rem; box-shadow: 0 1px 3px rgb(24 24 27 / 6%);
    }
    .card h2 {
      margin: 0 0 .75rem; font-family: var(--font-display); font-size: 1.05rem; font-weight: 800;
    }
    .tags { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 .85rem; }
    .tag {
      display: inline-flex; min-height: 28px; align-items: center; padding: 0 .65rem;
      border-radius: 999px; background: #fafafa; border: 1px solid var(--border);
      color: var(--muted); font-size: .8rem; font-weight: 600;
    }
    .rates { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .55rem; }
    .rate {
      background: #fafafa; border: 1px solid var(--border); border-radius: 14px; padding: .75rem .7rem;
    }
    .rate span { display: block; color: var(--muted); font-size: .78rem; margin-block-end: .2rem; }
    .rate strong {
      font-family: var(--font-display); font-size: 1rem; font-weight: 800;
      font-variant-numeric: tabular-nums;
    }
    .note { margin: .75rem 0 0; color: var(--muted); font-size: .86rem; }
    .cta {
      display: flex; align-items: center; justify-content: center; min-height: 48px;
      margin-block-start: .9rem; border-radius: 14px; background: var(--accent); color: #fff;
      text-decoration: none; font-family: var(--font-display); font-weight: 800;
    }
    .gallery h2 {
      margin: 0 0 .65rem; font-family: var(--font-display); font-size: 1.05rem; font-weight: 800;
    }
    .gallery__grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .55rem;
    }
    .shot {
      margin: 0; border-radius: 14px; overflow: hidden; border: 1px solid var(--border);
      aspect-ratio: 4 / 3; background: #e4e4e7;
    }
    .shot img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .muted { color: var(--muted); margin: 0; }
    footer {
      text-align: center; color: var(--muted); font-size: .8rem;
      padding: 1.35rem 1rem calc(1.5rem + env(safe-area-inset-bottom, 0px));
    }
    @media (max-width: 560px) {
      .rates { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      {$logoHtml}
      <div>
        <p class="brand__eyebrow">RPM Rent A Car</p>
        <h1>{$centerEsc}</h1>
      </div>
    </div>
  </header>
  <main>
    {$heroHtml}
    <section class="card">
      <h2>{$title}</h2>
      <div class="tags">
        <span class="tag">{$category}</span>
        <span class="tag">{$fuel}</span>
        <span class="tag">{$transmission}</span>
        <span class="tag">{$color}</span>
      </div>
      <div class="rates" aria-label="الأسعار">
        <div class="rate"><span>يومي</span><strong>{$daily} ج.م.</strong></div>
        <div class="rate"><span>نص شهر</span><strong>{$half} ج.م.</strong></div>
        <div class="rate"><span>شهري</span><strong>{$monthly} ج.م.</strong></div>
      </div>
      <p class="note">أقل مدة إيجار: {$minEsc} أيام · للتواصل: {$phoneEsc}</p>
      {$ctaHtml}
    </section>
    {$galleryHtml}
  </main>
  <footer>رابط مؤقت للمشاركة عبر واتساب · لا يتطلب تسجيل دخول · RPM</footer>
</body>
</html>
HTML;
    }

    /**
     * @return array{daily: int, half: int, monthly: int}
     */
    private function rates(Vehicle $vehicle): array
    {
        $daily = (int) $vehicle->daily_rate;
        $half = $vehicle->half_monthly_rate !== null
            ? (int) $vehicle->half_monthly_rate
            : (int) round($daily * ContractPricingService::DEFAULT_HALF_MONTHLY_FACTOR);
        $monthly = $vehicle->monthly_rate !== null
            ? (int) $vehicle->monthly_rate
            : (int) round($daily * ContractPricingService::DEFAULT_MONTHLY_FACTOR);

        return ['daily' => $daily, 'half' => $half, 'monthly' => $monthly];
    }

    /**
     * @return \Illuminate\Support\Collection<int, MediaFile>
     */
    private function galleryPhotos(Vehicle $vehicle)
    {
        return MediaFile::query()
            ->where('attachable_type', Vehicle::class)
            ->where('attachable_id', $vehicle->id)
            ->where(function ($q) {
                $q->where('collection', 'vehicle_photos')
                    ->orWhereIn('purpose', ['photo', 'primary', 'gallery'])
                    ->orWhere(function ($qq) {
                        $qq->where('kind', 'image')
                            ->where(function ($q3) {
                                $q3->whereNull('purpose')
                                    ->orWhereNotIn('purpose', ['document', 'license', 'national_id', 'damage']);
                            })
                            ->where(function ($q3) {
                                $q3->whereNull('collection')
                                    ->orWhere('collection', '!=', 'vehicle_docs');
                            });
                    });
            })
            ->orderByRaw("CASE WHEN purpose = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->limit(12)
            ->get();
    }

    private function moneyPlain(int $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }

    private function brandLogoHtml(?string $logoPath): string
    {
        $logoPath = is_string($logoPath) ? trim($logoPath) : '';
        if ($logoPath !== '') {
            $src = e(str_starts_with($logoPath, 'http')
                ? $logoPath
                : PublicAppUrl::to(ltrim($logoPath, '/')));

            return '<div class="brand__mark"><img src="'.$src.'" alt="شعار الشركة" /></div>';
        }

        return '<div class="brand__mark"><span>RPM</span></div>';
    }
}
