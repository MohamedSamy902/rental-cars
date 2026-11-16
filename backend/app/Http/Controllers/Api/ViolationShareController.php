<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\TenantSetting;
use App\Models\Violation;
use App\Services\Media\PublicMediaLinkService;
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

        $center = TenantSetting::query()->value('center_name') ?: 'شركة آر بي إم للتأجير';
        $customerName = e($rows->first()?->customer?->full_name ?: 'عميل');
        $centerEsc = e($center);

        $blocks = '';
        $total = 0;
        foreach ($rows as $index => $v) {
            $total += (int) $v->amount;
            $date = e($v->occurred_at?->timezone(config('app.timezone'))->format('Y-m-d') ?: '—');
            $amount = e(number_format((int) $v->amount, 0, '.', ','));
            $car = $v->vehicle
                ? e(trim($v->vehicle->brand.' '.$v->vehicle->model.' · '.$v->vehicle->plate))
                : '—';
            $contract = e($v->contract?->number ?: '—');
            $photosHtml = '';
            foreach ($v->resolvedPhotoPaths() as $path) {
                $mediaId = $this->links->mediaIdFromStored($path);
                if ($mediaId === null) {
                    continue;
                }
                if (! MediaFile::query()->whereKey($mediaId)->exists()) {
                    continue;
                }
                $src = e($this->links->temporaryFileUrl($mediaId));
                $photosHtml .= '<a class="shot" href="'.$src.'" target="_blank" rel="noopener"><img src="'.$src.'" alt="صورة مخالفة" loading="lazy" /></a>';
            }
            if ($photosHtml === '') {
                $photosHtml = '<p class="muted">لا توجد صور مرفقة.</p>';
            }
            $n = $index + 1;
            $blocks .= <<<HTML
<article class="card">
  <h2>مخالفة {$n}</h2>
  <dl>
    <div><dt>التاريخ</dt><dd>{$date}</dd></div>
    <div><dt>المبلغ</dt><dd>{$amount} ج.م.</dd></div>
    <div><dt>العربية</dt><dd>{$car}</dd></div>
    <div><dt>الحجز</dt><dd>{$contract}</dd></div>
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
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title>بيان مخالفات — {$centerEsc}</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Tahoma, Arial, sans-serif; background: #f4f6f8; color: #122; line-height: 1.5; }
    header { background: #0f766e; color: #fff; padding: 1.25rem 1rem 1.5rem; }
    header h1 { margin: 0 0 .35rem; font-size: 1.25rem; }
    header p { margin: 0; opacity: .92; font-size: .95rem; }
    main { padding: 1rem; max-width: 720px; margin: 0 auto; display: grid; gap: 1rem; }
    .summary { background: #fff; border-radius: 16px; padding: 1rem 1.1rem; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .summary strong { font-size: 1.15rem; }
    .card { background: #fff; border-radius: 16px; padding: 1rem 1.1rem; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .card h2 { margin: 0 0 .75rem; font-size: 1.05rem; }
    dl { margin: 0; display: grid; gap: .45rem; }
    dl div { display: flex; justify-content: space-between; gap: 1rem; border-bottom: 1px solid #eef2f5; padding-bottom: .35rem; }
    dt { color: #64748b; }
    dd { margin: 0; font-weight: 600; }
    .photos { margin-top: .9rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .65rem; }
    .shot { display: block; border-radius: 12px; overflow: hidden; background: #e2e8f0; aspect-ratio: 1; }
    .shot img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .muted { color: #64748b; margin: .5rem 0 0; }
    footer { text-align: center; color: #64748b; font-size: .85rem; padding: 1.5rem 1rem 2rem; }
  </style>
</head>
<body>
  <header>
    <h1>{$centerEsc}</h1>
    <p>بيان مخالفات — {$customerName}</p>
  </header>
  <main>
    <section class="summary">
      <div>عدد المخالفات: <strong>{$count}</strong></div>
      <div>الإجمالي: <strong>{$totalFmt} ج.م.</strong></div>
    </section>
    {$blocks}
  </main>
  <footer>رابط مؤقت للمشاركة عبر واتساب · لا يتطلب تسجيل دخول</footer>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
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
