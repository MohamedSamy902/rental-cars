<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\TenantSetting;
use App\Models\Violation;
use App\Services\Media\PublicMediaLinkService;
use App\Support\PublicAppUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * مشاركة مخالفات للعميل عبر واتساب: صفحة عامة موقّعة + صور موقّعة بلا تسجيل دخول.
 */
class ViolationShareController extends Controller
{
    public function __construct(private readonly PublicMediaLinkService $links) {}

    /**
     * موظف مسجّل: يجهّز رابط بيان عام + نص واتساب.
     */
    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'violation_ids' => ['required', 'array', 'min:1', 'max:50'],
            'violation_ids.*' => ['integer', 'min:1'],
        ], [], [
            'violation_ids' => 'المخالفات',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['violation_ids'])));
        $rows = Violation::query()
            ->with(['vehicle', 'customer', 'contract'])
            ->whereIn('id', $ids)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($rows->count() !== count($ids)) {
            return response()->json(['message' => 'بعض المخالفات غير موجودة.'], 422);
        }

        $shareUrl = $this->links->temporaryViolationsShareUrl($ids);
        $center = TenantSetting::query()->value('center_name') ?: 'شركة آر بي إم للتأجير';
        $customerName = $rows->first()?->customer?->full_name ?: '';
        $customerPhone = $rows->first(fn (Violation $v) => filled($v->customer?->phone))?->customer?->phone;

        $lines = [
            $customerName !== ''
                ? "{$center} — بيان مخالفات للعميل {$customerName}"
                : "{$center} — بيان مخالفات",
            '',
            'افتح البيان والصور من الرابط:',
            $shareUrl,
            '',
        ];

        $total = 0;
        foreach ($rows->values() as $i => $v) {
            $total += (int) $v->amount;
            $n = $i + 1;
            $date = $v->occurred_at?->timezone(config('app.timezone'))->format('Y-m-d') ?: '—';
            $amount = number_format((int) $v->amount, 0, '.', ',');
            $lines[] = "{$n}) تاريخ {$date} · المبلغ {$amount} ج.م.";
            if ($v->vehicle) {
                $lines[] = '   العربية: '.$v->vehicle->brand.' '.$v->vehicle->model.' · '.$v->vehicle->plate;
            }
            if ($v->contract?->number) {
                $lines[] = '   الحجز: '.$v->contract->number;
            }
        }
        $lines[] = '';
        $lines[] = 'الإجمالي: '.number_format($total, 0, '.', ',').' ج.م.';
        $lines[] = 'عدد المخالفات: '.$rows->count();
        $lines[] = 'الرابط صالح لمدة '.PublicMediaLinkService::DEFAULT_TTL_DAYS.' يوماً.';

        return response()->json([
            'data' => [
                'share_url' => $shareUrl,
                'message_text' => implode("\n", $lines),
                'customer_phone' => $customerPhone,
                'expires_in_days' => PublicMediaLinkService::DEFAULT_TTL_DAYS,
            ],
        ]);
    }

    /**
     * صفحة عامة (HTML) يفتحها العميل من واتساب بلا لوجين.
     */
    public function publicShare(Request $request): Response
    {
        $rawIds = (string) $request->query('ids', '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $rawIds)), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return response('الرابط غير صالح.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $rows = Violation::query()
            ->with(['vehicle', 'customer', 'contract'])
            ->whereIn('id', $ids)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $tenant = TenantSetting::query()->first();
        $center = $tenant?->center_name ?: 'شركة آر بي إم للتأجير';
        $customerName = e($rows->first()?->customer?->full_name ?: 'عميل');
        $centerEsc = e($center);
        $logoHtml = $this->brandLogoHtml($tenant?->logo_path);

        $blocks = '';
        $total = 0;
        foreach ($rows as $index => $v) {
            $total += (int) $v->amount;
            $date = e($v->occurred_at?->timezone(config('app.timezone'))->format('d/m/Y') ?: '—');
            $amount = e(number_format((int) $v->amount, 0, '.', ','));
            $car = $v->vehicle
                ? e(trim($v->vehicle->brand.' '.$v->vehicle->model.' · '.$v->vehicle->plate))
                : '—';
            $contract = e($v->contract?->number ?: '—');
            $photosHtml = '';
            $photoIndex = 0;
            foreach ($v->resolvedPhotoPaths() as $path) {
                $mediaId = $this->links->mediaIdFromStored($path);
                if ($mediaId === null) {
                    continue;
                }
                if (! MediaFile::query()->whereKey($mediaId)->exists()) {
                    continue;
                }
                $photoIndex++;
                $src = e($this->links->temporaryFileUrl($mediaId));
                $photosHtml .= <<<HTML
<figure class="shot">
  <a class="shot__preview" href="{$src}" target="_blank" rel="noopener">
    <img src="{$src}" alt="صورة مخالفة {$photoIndex}" loading="lazy" />
  </a>
  <a class="shot__btn" href="{$src}" target="_blank" rel="noopener" download>تحميل نموذج المخالفة</a>
</figure>
HTML;
            }
            if ($photosHtml === '') {
                $photosHtml = '<p class="muted">لا توجد صور مرفقة.</p>';
            }
            $n = $index + 1;
            $blocks .= <<<HTML
<article class="card">
  <div class="card__head">
    <span class="pill">مخالفة {$n}</span>
  </div>
  <dl>
    <div><dt>التاريخ</dt><dd class="tabular">{$date}</dd></div>
    <div><dt>المبلغ</dt><dd class="tabular amount">{$amount} ج.م.</dd></div>
    <div><dt>العربية</dt><dd>{$car}</dd></div>
    <div><dt>الحجز</dt><dd class="tabular">{$contract}</dd></div>
  </dl>
  <div class="photos">{$photosHtml}</div>
</article>
HTML;
        }

        $totalFmt = e(number_format($total, 0, '.', ','));
        $count = $rows->count();
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="theme-color" content="#18181b" />
  <title>بيان مخالفات — {$centerEsc}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@500;700;800&display=swap" rel="stylesheet" />
  <style>
    :root {
      color-scheme: light;
      --ink: #18181b;
      --ink-soft: #27272a;
      --bg: #f4f4f5;
      --surface: #ffffff;
      --surface-2: #fafafa;
      --text: #18181b;
      --text-muted: #71717a;
      --text-faint: #a1a1aa;
      --accent: #dc2626;
      --accent-hover: #b91c1c;
      --accent-soft: #fef2f2;
      --border: #e4e4e7;
      --border-strong: #d4d4d8;
      --shadow: 0 10px 28px rgb(24 24 27 / 7%);
      --shadow-sm: 0 1px 3px rgb(24 24 27 / 7%), 0 1px 2px rgb(24 24 27 / 4%);
      --radius: 16px;
      --radius-lg: 20px;
      --font-display: "Tajawal", "IBM Plex Sans Arabic", sans-serif;
      --font-body: "IBM Plex Sans Arabic", "Tajawal", sans-serif;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100dvh;
      font-family: var(--font-body);
      background:
        radial-gradient(1200px 420px at 100% -10%, color-mix(in srgb, var(--accent) 10%, transparent), transparent 55%),
        var(--bg);
      color: var(--text);
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }
    header {
      background: linear-gradient(160deg, var(--ink) 0%, var(--ink-soft) 100%);
      color: #fff;
      padding: calc(1.1rem + env(safe-area-inset-top, 0px)) 1.15rem 1.55rem;
      border-end-start-radius: 28px;
      border-end-end-radius: 28px;
      box-shadow: 0 18px 40px rgb(24 24 27 / 18%);
    }
    .brand {
      display: flex;
      align-items: center;
      gap: .85rem;
      margin-block-end: 1rem;
    }
    .brand__mark {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      background: #0a0a0a;
      border: 1px solid rgb(255 255 255 / 12%);
      display: grid;
      place-items: center;
      overflow: hidden;
      flex: 0 0 auto;
    }
    .brand__mark img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .brand__mark span {
      font-family: var(--font-display);
      font-weight: 800;
      font-size: .85rem;
      letter-spacing: .04em;
      color: #fff;
    }
    .brand__text { min-width: 0; }
    .brand__eyebrow {
      margin: 0;
      color: var(--text-faint);
      font-size: .78rem;
      font-weight: 500;
    }
    header h1 {
      margin: .15rem 0 0;
      font-family: var(--font-display);
      font-size: 1.28rem;
      font-weight: 800;
      letter-spacing: -.01em;
    }
    .hero-sub {
      margin: .85rem 0 0;
      padding: .75rem .9rem;
      border-radius: 14px;
      background: rgb(255 255 255 / 7%);
      border: 1px solid rgb(255 255 255 / 10%);
      font-size: .95rem;
    }
    .hero-sub strong { color: #fff; font-weight: 700; }
    main {
      padding: 1.1rem 1rem 0;
      max-width: 720px;
      margin: 0 auto;
      display: grid;
      gap: .9rem;
    }
    .summary, .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 1rem 1.05rem;
      box-shadow: var(--shadow-sm);
    }
    .summary {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .75rem;
      margin-block-start: -.35rem;
    }
    .kpi {
      background: var(--surface-2);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: .85rem .9rem;
    }
    .kpi span {
      display: block;
      color: var(--text-muted);
      font-size: .82rem;
      margin-block-end: .25rem;
    }
    .kpi strong {
      font-family: var(--font-display);
      font-size: 1.2rem;
      font-weight: 800;
      letter-spacing: -.01em;
    }
    .kpi--accent strong { color: var(--accent); }
    .card__head { margin-block-end: .85rem; }
    .pill {
      display: inline-flex;
      align-items: center;
      padding: .28rem .7rem;
      border-radius: 999px;
      background: var(--accent-soft);
      color: var(--accent-hover);
      font-family: var(--font-display);
      font-size: .82rem;
      font-weight: 700;
    }
    dl { margin: 0; display: grid; gap: .55rem; }
    dl div {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      border-bottom: 1px solid var(--border);
      padding-block-end: .45rem;
    }
    dl div:last-child { border-bottom: 0; padding-block-end: 0; }
    dt { color: var(--text-muted); font-size: .92rem; }
    dd { margin: 0; font-weight: 600; text-align: start; }
    .amount { color: var(--accent); font-family: var(--font-display); font-weight: 800; }
    .tabular { font-variant-numeric: tabular-nums; letter-spacing: .01em; }
    .photos {
      margin-block-start: 1rem;
      display: grid;
      gap: .75rem;
    }
    .shot {
      margin: 0;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      background: var(--surface-2);
    }
    .shot__preview {
      display: block;
      aspect-ratio: 4 / 3;
      background: #e4e4e7;
    }
    .shot__preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .shot__btn {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: .7rem 1rem;
      background: var(--ink);
      color: #fff;
      text-decoration: none;
      font-family: var(--font-display);
      font-weight: 700;
      font-size: .92rem;
      transition: background 150ms ease;
    }
    .shot__btn:hover, .shot__btn:focus-visible { background: var(--accent); }
    .muted { color: var(--text-muted); margin: .35rem 0 0; font-size: .92rem; }
    footer {
      text-align: center;
      color: var(--text-muted);
      font-size: .82rem;
      padding: 1.4rem 1rem calc(1.6rem + env(safe-area-inset-bottom, 0px));
    }
    footer .rpm {
      display: inline-block;
      margin-block-start: .35rem;
      color: var(--text-faint);
      font-family: var(--font-display);
      font-weight: 700;
      letter-spacing: .06em;
    }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      {$logoHtml}
      <div class="brand__text">
        <p class="brand__eyebrow">RPM Rent A Car</p>
        <h1>{$centerEsc}</h1>
      </div>
    </div>
    <p class="hero-sub">بيان مخالفات — <strong>{$customerName}</strong></p>
  </header>
  <main>
    <section class="summary" aria-label="ملخص">
      <div class="kpi"><span>عدد المخالفات</span><strong class="tabular">{$count}</strong></div>
      <div class="kpi kpi--accent"><span>الإجمالي</span><strong class="tabular">{$totalFmt} ج.م.</strong></div>
    </section>
    {$blocks}
  </main>
  <footer>
    رابط مؤقت للمشاركة عبر واتساب · لا يتطلب تسجيل دخول
    <div class="rpm">RPM</div>
  </footer>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
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

    /**
     * ملف ميديا عام عبر توقيع مؤقت.
     */
    public function publicMediaFile(Request $request, MediaFile $medium): StreamedResponse|JsonResponse|Response
    {
        if (! Storage::disk($medium->disk)->exists($medium->path)) {
            return response()->json(['message' => __('http.not_found')], 404);
        }

        return Storage::disk($medium->disk)->response(
            $medium->path,
            $medium->original_name,
            [
                'Content-Type' => $medium->mime_type,
                'Content-Disposition' => 'inline; filename="'.$medium->original_name.'"',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }
}
