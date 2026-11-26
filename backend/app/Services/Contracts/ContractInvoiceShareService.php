<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\Violation;
use App\Services\Media\PublicMediaLinkService;
use App\Support\CivilDate;
use App\Support\DepositCurrency;
use App\Support\PublicAppUrl;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * تجهيز فاتورة داخلية عامة موقّعة للمشاركة عبر واتساب (مثل بيان المخالفات).
 */
class ContractInvoiceShareService
{
    public function __construct(
        private readonly PublicMediaLinkService $links,
        private readonly ContractLedgerMath $ledgerMath,
        private readonly ContractPricingService $pricing,
    ) {}

    /**
     * صلاحية رابط الفاتورة: شهر بعد نهاية الإيجار المخططة/الفعلية (حد أدنى ٣٠ يوماً من الآن).
     */
    public function invoiceShareExpiresAt(Contract $contract): Carbon
    {
        $end = $contract->actual_ends_at ?? $contract->planned_ends_at ?? now();
        $expires = Carbon::parse($end)->addMonth()->endOfDay();
        $min = now()->copy()->addDays(30)->endOfDay();
        if ($expires->lt($min)) {
            return $min;
        }

        return $expires;
    }

    public function invoiceShareTtlDays(Contract $contract): int
    {
        $seconds = max(86_400, $this->invoiceShareExpiresAt($contract)->getTimestamp() - now()->getTimestamp());

        return max(1, (int) ceil($seconds / 86_400));
    }

    /**
     * @return array{share_url: string, message_text: string, customer_phone: ?string, expires_in_days: int, invoice_number: string}
     */
    public function prepare(Contract $contract): array
    {
        $contract->loadMissing(['customer', 'vehicle', 'deposit', 'inspections']);
        $ttlDays = $this->invoiceShareTtlDays($contract);
        $shareUrl = $this->links->temporaryContractInvoiceShareUrl((int) $contract->id, $ttlDays);
        $tenant = TenantSetting::query()->first();
        $center = $tenant?->center_name ?: 'شركة آر بي إم للتأجير';
        $number = $contract->number ?: ('C-'.$contract->id);
        $invoiceNumber = $this->invoiceNumber($number);
        $totals = $this->totals($contract);
        $customerName = trim((string) ($contract->customer?->full_name ?? ''));
        $customerPhone = filled($contract->customer?->phone) ? (string) $contract->customer->phone : null;

        $lines = [
            $customerName !== ''
                ? "{$center} — فاتورة للعقد {$number} · العميل {$customerName}"
                : "{$center} — فاتورة للعقد {$number}",
            '',
            'افتح الفاتورة من الرابط:',
            $shareUrl,
            '',
            'رقم الفاتورة: '.$invoiceNumber,
        ];
        if ($contract->vehicle) {
            $lines[] = 'العربية: '.trim($contract->vehicle->brand.' '.$contract->vehicle->model).' · '.$contract->vehicle->plate;
        }
        if ($totals['violations_count'] > 0) {
            $lines[] = 'مخالفات: '.$totals['violations_count']
                .' · '.$this->moneyPlain($totals['violations_billable']).' ج.م.';
        }
        $lines[] = 'الإجمالي: '.$this->moneyPlain($totals['total']).' ج.م.';
        if ($totals['paid_net'] > 0) {
            $lines[] = 'المدفوع: '.$this->moneyPlain($totals['paid_net']).' ج.م.';
        }
        $lines[] = 'المتبقي: '.$this->moneyPlain($totals['remaining']).' ج.م.';

        return [
            'share_url' => $shareUrl,
            'message_text' => implode("\n", $lines),
            'customer_phone' => $customerPhone,
            'expires_in_days' => $ttlDays,
            'invoice_number' => $invoiceNumber,
        ];
    }

    public function renderPublicHtml(Contract $contract): string
    {
        $contract->loadMissing(['customer', 'vehicle', 'deposit', 'inspections']);
        $tenant = TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];
        $center = $tenant?->center_name ?: 'شركة آر بي إم للتأجير';
        $number = $contract->number ?: ('C-'.$contract->id);
        $totals = $this->totals($contract);
        $customer = $contract->customer;
        $vehicle = $contract->vehicle;

        $handover = $contract->inspections->firstWhere('type', 'handover');
        $ret = $contract->inspections->firstWhere('type', 'return');
        $outAt = $handover?->inspected_at ?? $contract->starts_at;
        $inAt = $ret?->inspected_at ?? $contract->actual_ends_at ?? $contract->planned_ends_at;
        $odoOut = $handover?->odometer ?? $contract->odometer_out;
        $odoIn = $ret?->odometer ?? $contract->odometer_in;

        $days = $this->rentalDays($contract);
        $basis = $this->pricing->resolveRentBasisFromDays(max(1, $days));
        [$rateLabel, $unitRate] = $this->rateDisplay($contract, $basis);

        $deposit = (int) ($contract->deposit?->original_amount ?? $contract->frozen_agreed_deposit ?? 0);
        $depositCurrency = DepositCurrency::normalize($contract->frozen_deposit_currency ?? null);
        $prepaidQty = (int) ($contract->prepaid_excess_km ?? 0);
        $prepaidAmt = (int) ($contract->prepaid_excess_km_amount ?? 0);
        $excessQty = (int) ($contract->excess_km ?? 0);
        $excessAmt = (int) ($contract->excess_km_amount ?? 0);
        $other = (int) ($contract->other_fees ?? 0);
        $damage = (int) ($contract->damage_fee ?? 0);
        $delivery = (int) ($contract->delivery_service_fee ?? 0);
        $hasDelivery = (bool) ($contract->has_delivery_service ?? false) || $delivery > 0;
        $otherLabel = trim((string) ($contract->other_fees_description ?? ''));
        $deliveryNotes = trim((string) ($contract->delivery_service_notes ?? ''));
        $nationality = $this->nationalityOf($customer?->nationality, $customer?->national_id);

        $logoSrc = $this->logoSrc($tenant?->logo_path);
        $invNo = e($this->invoiceNumber($number));
        $invDate = e(CivilDate::formatDisplay($contract->starts_at, ''));
        $centerEsc = e($center);
        $logoEsc = e($logoSrc);
        $numberEsc = e($number);
        $printCr = e((string) ($ops['print_commercial_register'] ?? ''));
        $printFooter = trim((string) ($tenant?->print_footer ?? ''));
        $printFooterHtml = $printFooter !== ''
            ? '<p class="inv-print-footer">'.e($printFooter).'</p>'
            : '';
        $authorName = e((string) config('rpm.platform_author_name', 'محمد سامي'));
        $authorWa = e((string) config('rpm.platform_whatsapp_url', 'https://wa.me/201150100104'));
        $creditHtml = '<aside class="inv-credit">تم الإنشاء بواسطة '
            .'<a href="'.$authorWa.'" target="_blank" rel="noopener noreferrer">'.$authorName.'</a>'
            .'</aside>';

        $customerName = e((string) ($customer?->full_name ?? '—'));
        $customerPhone = e((string) ($customer?->phone ?? '—'));
        $customerAddress = e((string) ($customer?->address ?? '—'));
        $idOrPassport = e((string) ($customer?->national_id ?: $customer?->passport_number ?: ($ops['print_tax_info'] ?? '—')));
        $idExpiryRaw = $customer?->national_id_expires_on ?? $customer?->license_expires_on;
        $idExpiry = e($idExpiryRaw ? CivilDate::formatDisplay($idExpiryRaw, '—') : '—');
        $nationalityEsc = e($nationality !== '' ? $nationality : '—');
        $crCell = $printCr !== '' ? $printCr : '—';
        $vehicleName = e(trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')) ?: '—');
        $vehicleYear = e($this->dash($vehicle?->year) ?: '—');
        $vehiclePlate = e((string) ($vehicle?->plate ?? '—'));
        $vehicleColor = e((string) ($vehicle?->color ?: '—'));
        $outAtLabel = e($this->whenLabel($outAt) ?: '—');
        $inAtLabel = e($this->whenLabel($inAt) ?: '—');
        $odoOutLabel = $odoOut != null ? e(number_format((int) $odoOut, 0, '.', ',').' كم') : '—';
        $odoInLabel = $odoIn != null ? e(number_format((int) $odoIn, 0, '.', ',').' كم') : '—';

        $rateLabelEsc = e($rateLabel);
        $daysCell = $days > 0 ? (string) $days : '—';
        $unitRateCell = $unitRate > 0 ? e($this->moneyPlain($unitRate)) : '—';
        $rentCell = e($this->moneyPlain($totals['rent']));
        $depositCell = $deposit > 0
            ? e(number_format($deposit, 0, '.', ',').' '.$depositCurrency)
            : '—';
        $prepaidQtyCell = $prepaidQty > 0 ? (string) $prepaidQty : '—';
        $prepaidAmtCell = $prepaidAmt > 0 ? e($this->moneyPlain($prepaidAmt)) : '—';
        $excessQtyCell = $excessQty > 0 ? (string) $excessQty : '—';
        $excessAmtCell = $excessAmt > 0 ? e($this->moneyPlain($excessAmt)) : '—';
        $deliveryDesc = 'خدمة تسليم واستلام'
            .($deliveryNotes !== '' ? ' · '.e($deliveryNotes) : '');
        $deliveryAmt = $hasDelivery ? e($this->moneyPlain($delivery)) : '—';
        $otherDesc = 'رسوم أخرى / خدمات'
            .($damage > 0 ? ' · أضرار' : '')
            .($otherLabel !== '' ? ' · '.e($otherLabel) : '');
        $otherAmt = ($other > 0 || $damage > 0) ? e($this->moneyPlain($other + $damage)) : '—';
        $violationRowsHtml = $this->violationRowsHtml($totals['violations']);
        $violationsNote = $totals['violations_count'] > 0
            ? '<p class="inv-note">المخالفات الظاهرة تُحدَّث تلقائياً عند تسجيل مخالفة جديدة على العقد. المسدَّد منها لا يُضاف للمتبقي.</p>'
            : '';
        $totalCell = e($this->moneyPlain($totals['total']));
        $paidCell = e($this->moneyPlain($totals['paid_net']));
        $remainingCell = e($this->moneyPlain($totals['remaining']));
        $remainingTone = $totals['remaining'] > 0 ? 'is-due' : 'is-settled';

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <title>فاتورة {$invNo} — {$centerEsc}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    @page { size: A4; margin: 12mm 10mm; }
    :root{
      --ink:#0f172a;
      --muted:#64748b;
      --line:#e2e8f0;
      --surface:#ffffff;
      --bg:#eef2f7;
      --accent:#b91c1c;
      --accent-soft:#fef2f2;
      --navy:#0b1f3a;
      --ok:#047857;
      --warn:#b45309;
      --radius:18px;
      --shadow:0 18px 50px rgba(15,23,42,.08);
    }
    *{ box-sizing:border-box; }
    html,body{ margin:0; }
    body{
      min-height:100vh;
      padding:18px 14px calc(28px + env(safe-area-inset-bottom, 0px));
      background:
        radial-gradient(1200px 420px at 100% -10%, rgba(185,28,28,.10), transparent 55%),
        radial-gradient(900px 380px at -10% 0%, rgba(11,31,58,.10), transparent 50%),
        linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
      color:var(--ink);
      font-family:"IBM Plex Sans Arabic",Tahoma,"Segoe UI",Arial,sans-serif;
      font-size:14px; line-height:1.55;
      -webkit-font-smoothing:antialiased;
      -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    .sheet{
      max-width:760px; margin:0 auto;
      background:var(--surface);
      border:1px solid rgba(226,232,240,.9);
      border-radius:24px;
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .rpm-invoice-v3{ padding:22px 18px 16px; }
    .inv-banner{
      display:flex; flex-wrap:wrap; justify-content:space-between; gap:18px;
      align-items:flex-start;
      margin:0 0 18px; padding:16px 16px 14px;
      border-radius:16px;
      background:linear-gradient(135deg,#0b1f3a 0%,#16365f 55%,#1e3a5f 100%);
      color:#fff;
    }
    .inv-brand{ display:flex; align-items:center; gap:12px; min-width:0; }
    .inv-brand img{
      width:64px; height:64px; object-fit:contain;
      border-radius:16px; background:#fff;
      border:1px solid rgba(255,255,255,.28);
      padding:6px;
    }
    .inv-brand-text{ display:flex; flex-direction:column; gap:4px; min-width:0; }
    .inv-brand-text strong{
      font-size:1.05rem; font-weight:700; color:#fff;
      letter-spacing:-.01em;
    }
    .inv-brand-text span{
      display:inline-flex; align-self:flex-start;
      font-size:.72rem; font-weight:700; letter-spacing:.08em;
      color:#fff; background:rgba(185,28,28,.92);
      border-radius:999px; padding:3px 10px;
    }
    .inv-title-block{ text-align:start; min-width:min(100%, 240px); }
    .inv-kicker{ margin:0 0 2px; font-size:.78rem; color:rgba(255,255,255,.78); font-weight:500; }
    .inv-title-block h1{
      margin:0 0 10px; font-size:1.65rem; font-weight:700;
      color:#fff; letter-spacing:-.02em;
    }
    .inv-meta{ display:grid; gap:6px; }
    .inv-meta-row{
      display:flex; flex-wrap:wrap; gap:6px 10px; align-items:baseline;
      font-size:.9rem;
    }
    .inv-meta-row .lbl{ color:rgba(255,255,255,.72); font-weight:500; }
    .inv-meta-row .val{
      font-weight:700; font-variant-numeric:tabular-nums; color:#0b1f3a;
      background:#fff; border:0;
      border-radius:999px; padding:2px 10px;
    }
    .inv-section{ margin-top:18px; }
    .inv-section-title{
      display:flex; align-items:center; gap:8px;
      margin:0 0 10px; font-size:.95rem; font-weight:700; color:var(--navy);
    }
    .inv-section-title::before{
      content:""; width:8px; height:8px; border-radius:999px; background:var(--accent);
      box-shadow:0 0 0 4px var(--accent-soft);
    }
    .inv-cards{
      display:grid; grid-template-columns:1fr 1fr; gap:10px;
    }
    .inv-card{
      background:#f8fafc; border:1px solid var(--line);
      border-radius:16px; padding:12px 14px;
    }
    .inv-card .k{
      display:block; font-size:.75rem; color:var(--muted); margin-bottom:2px; font-weight:500;
    }
    .inv-card .v{
      display:block; font-weight:600; font-size:.95rem; word-break:break-word;
      font-variant-numeric:tabular-nums;
    }
    .inv-card.wide{ grid-column:1 / -1; }
    .inv-finance{
      margin-top:8px; border:1px solid var(--line); border-radius:18px; overflow:hidden;
      background:#fff;
    }
    .inv-finance-table{ width:100%; border-collapse:collapse; }
    .inv-finance-table thead th{
      background:linear-gradient(180deg, #0b1f3a 0%, #123056 100%);
      color:#fff; font-weight:600; font-size:.8rem;
      padding:11px 12px; text-align:start;
    }
    .inv-finance-table td{
      padding:11px 12px; border-top:1px solid var(--line);
      font-size:.9rem; vertical-align:middle;
    }
    .inv-finance-table tbody tr:nth-child(even) td{ background:#fbfdff; }
    .inv-finance-table .desc{ text-align:start; }
    .inv-finance-table .num{
      text-align:center; font-variant-numeric:tabular-nums; font-weight:600; white-space:nowrap;
    }
    .inv-finance-table tr.totals td{
      background:#f1f5f9; font-weight:700;
    }
    .inv-finance-table tr.balance td{
      background:var(--accent-soft);
    }
    .inv-finance-table tr.balance.is-settled td{ background:#ecfdf5; }
    .inv-finance-table tr.balance .num{ color:var(--warn); font-size:1.05rem; }
    .inv-finance-table tr.balance.is-settled .num{ color:var(--ok); }
    .inv-note{
      margin:10px 2px 0; font-size:.8rem; color:var(--muted);
    }
    .inv-terms{
      margin-top:18px; background:#f8fafc; border:1px solid var(--line);
      border-radius:16px; padding:14px 16px;
    }
    .inv-terms h3{ margin:0 0 8px; font-size:.95rem; color:var(--navy); }
    .inv-terms ol{ margin:0; padding-inline-start:1.2rem; color:#334155; font-size:.88rem; }
    .inv-terms li{ margin:5px 0; }
    .inv-sign{
      display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:22px;
    }
    .inv-sign .box{
      border:1px dashed #cbd5e1; border-radius:14px; padding:14px;
      min-height:88px;
    }
    .inv-sign strong{ display:block; margin-bottom:36px; font-size:.85rem; color:var(--muted); font-weight:600; }
    .inv-foot{
      display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;
      margin-top:18px; padding-top:12px; border-top:1px solid var(--line);
      font-size:.75rem; color:var(--muted);
    }
    .inv-print-footer{ margin-top:8px; font-size:.75rem; color:var(--muted); text-align:center; }
    .inv-credit{
      margin-top:16px; padding:10px 12px;
      border:1px solid var(--line); border-radius:12px; background:#f8fafc;
      text-align:center; font-size:.82rem; color:var(--muted);
    }
    .inv-credit a{
      color:#0b1f3a; font-weight:800; text-decoration:none;
      border-bottom:2px solid rgba(185,28,28,.45);
    }
    @media (max-width:640px){
      body{ padding:10px 8px 22px; }
      .rpm-invoice-v3{ padding:16px 12px 12px; }
      .inv-brand img{ width:52px; height:52px; }
      .inv-title-block h1{ font-size:1.3rem; }
      .inv-cards{ grid-template-columns:1fr; }
      .inv-finance-table thead{ display:none; }
      .inv-finance-table, .inv-finance-table tbody, .inv-finance-table tr, .inv-finance-table td{
        display:block; width:100%;
      }
      .inv-finance-table tr{ padding:10px 12px; border-top:1px solid var(--line); }
      .inv-finance-table td{
        border:0; padding:3px 0; text-align:start !important;
      }
      .inv-finance-table td.desc{ font-weight:700; margin-bottom:4px; }
      .inv-finance-table td.num::before{
        content:attr(data-label) ": ";
        color:var(--muted); font-weight:500;
      }
      .inv-sign{ grid-template-columns:1fr; }
    }
    @media print{
      body{ background:#fff; padding:0; }
      .sheet{ box-shadow:none; border:0; border-radius:0; }
      .rpm-invoice-v3{ padding:0; }
      .inv-finance-table thead{ display:table-header-group; }
      .inv-finance-table, .inv-finance-table tbody, .inv-finance-table tr, .inv-finance-table td{
        display:table; width:auto;
      }
      .inv-finance-table tr{ display:table-row; }
      .inv-finance-table td{ display:table-cell; }
      .inv-finance-table td.num::before{ content:none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
<article class="rpm-invoice-v3" data-invoice-skin="v3-2026-11">
  <header class="inv-banner">
    <div class="inv-brand">
      <img src="{$logoEsc}" alt="{$centerEsc}" />
      <div class="inv-brand-text">
        <strong>{$centerEsc}</strong>
        <span>تأجير سيارات · فاتورة داخلية</span>
      </div>
    </div>
    <div class="inv-title-block">
      <p class="inv-kicker">ملخص اتفاق الإيجار</p>
      <h1>فاتورة</h1>
      <div class="inv-meta">
        <div class="inv-meta-row"><span class="lbl">رقم الفاتورة</span><span class="val" dir="ltr">{$invNo}</span></div>
        <div class="inv-meta-row"><span class="lbl">التاريخ</span><span class="val" dir="ltr">{$invDate}</span></div>
        <div class="inv-meta-row"><span class="lbl">رقم العقد</span><span class="val" dir="ltr">{$numberEsc}</span></div>
      </div>
    </div>
  </header>

  <section class="inv-section">
    <h2 class="inv-section-title">بيانات المستأجر</h2>
    <div class="inv-cards">
      <div class="inv-card"><span class="k">الاسم</span><span class="v">{$customerName}</span></div>
      <div class="inv-card"><span class="k">الهاتف</span><span class="v" dir="ltr">{$customerPhone}</span></div>
      <div class="inv-card"><span class="k">قومي / هوية</span><span class="v" dir="ltr">{$idOrPassport}</span></div>
      <div class="inv-card"><span class="k">الجنسية</span><span class="v">{$nationalityEsc}</span></div>
      <div class="inv-card"><span class="k">انتهاء الهوية / الرخصة</span><span class="v" dir="ltr">{$idExpiry}</span></div>
      <div class="inv-card"><span class="k">سجل تجاري</span><span class="v" dir="ltr">{$crCell}</span></div>
      <div class="inv-card wide"><span class="k">العنوان</span><span class="v">{$customerAddress}</span></div>
    </div>
  </section>

  <section class="inv-section">
    <h2 class="inv-section-title">بيانات السيارة</h2>
    <div class="inv-cards">
      <div class="inv-card"><span class="k">النوع / الموديل</span><span class="v">{$vehicleName}</span></div>
      <div class="inv-card"><span class="k">سنة الصنع</span><span class="v" dir="ltr">{$vehicleYear}</span></div>
      <div class="inv-card"><span class="k">اللوحة</span><span class="v">{$vehiclePlate}</span></div>
      <div class="inv-card"><span class="k">اللون</span><span class="v">{$vehicleColor}</span></div>
      <div class="inv-card"><span class="k">تاريخ الخروج</span><span class="v" dir="ltr">{$outAtLabel}</span></div>
      <div class="inv-card"><span class="k">عداد الخروج</span><span class="v" dir="ltr">{$odoOutLabel}</span></div>
      <div class="inv-card"><span class="k">تاريخ الدخول</span><span class="v" dir="ltr">{$inAtLabel}</span></div>
      <div class="inv-card"><span class="k">عداد الدخول</span><span class="v" dir="ltr">{$odoInLabel}</span></div>
    </div>
  </section>

  <section class="inv-section">
    <h2 class="inv-section-title">تفاصيل الحساب</h2>
    <div class="inv-finance">
      <table class="inv-finance-table">
        <thead>
          <tr>
            <th>البيان</th>
            <th>الكمية / الأيام</th>
            <th>{$rateLabelEsc}</th>
            <th>الإجمالي</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="desc">إيجار السيارة</td>
            <td class="num" data-label="الأيام">{$daysCell}</td>
            <td class="num" data-label="{$rateLabelEsc}">{$unitRateCell}</td>
            <td class="num" data-label="الإجمالي">{$rentCell}</td>
          </tr>
          <tr>
            <td class="desc">تأمين مؤقت</td>
            <td class="num" data-label="الكمية">—</td>
            <td class="num" data-label="السعر">—</td>
            <td class="num" data-label="الإجمالي">{$depositCell}</td>
          </tr>
          <tr>
            <td class="desc">كم زيادة مدفوعة مقدماً</td>
            <td class="num" data-label="الكمية">{$prepaidQtyCell}</td>
            <td class="num" data-label="السعر">—</td>
            <td class="num" data-label="الإجمالي">{$prepaidAmtCell}</td>
          </tr>
          <tr>
            <td class="desc">زيادة كم عند الاستلام</td>
            <td class="num" data-label="الكمية">{$excessQtyCell}</td>
            <td class="num" data-label="السعر">—</td>
            <td class="num" data-label="الإجمالي">{$excessAmtCell}</td>
          </tr>
          <tr>
            <td class="desc">{$deliveryDesc}</td>
            <td class="num" data-label="الكمية">—</td>
            <td class="num" data-label="السعر">—</td>
            <td class="num" data-label="الإجمالي">{$deliveryAmt}</td>
          </tr>
          <tr>
            <td class="desc">{$otherDesc}</td>
            <td class="num" data-label="الكمية">—</td>
            <td class="num" data-label="السعر">—</td>
            <td class="num" data-label="الإجمالي">{$otherAmt}</td>
          </tr>
          {$violationRowsHtml}
          <tr class="totals">
            <td class="desc" colspan="3">المبلغ الإجمالي</td>
            <td class="num" data-label="الإجمالي"><strong>{$totalCell}</strong></td>
          </tr>
          <tr class="totals">
            <td class="desc" colspan="3">المبلغ المدفوع</td>
            <td class="num" data-label="المدفوع">{$paidCell}</td>
          </tr>
          <tr class="balance {$remainingTone}">
            <td class="desc" colspan="3">المتبقي</td>
            <td class="num" data-label="المتبقي"><strong>{$remainingCell}</strong></td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="inv-note">التأمين المؤقت خارج إجمالي الإيجار ويُسوَّى عند الاستلام.</p>
    {$violationsNote}
  </section>

  <section class="inv-terms">
    <h3>الشروط والأحكام</h3>
    <ol>
      <li>يتعهد المستأجر بإعادة السيارة بنفس الحالة التي استلمها عليها وفي الوقت المحدد بالعقد.</li>
      <li>المستأجر مسؤول عن كافة المخالفات المرورية التي تُسجَّل على السيارة خلال فترة الإيجار.</li>
      <li>يمنع استخدام السيارة في غير الأغراض المخصصة لها أو قيادتها من غير الأشخاص المصرّح لهم.</li>
    </ol>
  </section>

  <footer class="inv-sign">
    <div class="box">
      <strong>توقيع المستأجر</strong>
    </div>
    <div class="box">
      <strong>توقيع المعرض / المفوض</strong>
    </div>
  </footer>

  <div class="inv-foot">
    <span>{$centerEsc}</span>
    <span>صفحة ١ من ١</span>
  </div>
  {$printFooterHtml}
  {$creditHtml}
</article>
  </div>
</body>
</html>
HTML;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function rateDisplay(Contract $contract, string $basis): array
    {
        $daily = (int) ($contract->frozen_daily_rate ?? 0);

        return match ($basis) {
            ContractPricingService::BASIS_MONTHLY => [
                'السعر الشهري',
                (int) ($contract->frozen_monthly_rate
                    ?: round($daily * ContractPricingService::DEFAULT_MONTHLY_FACTOR)),
            ],
            ContractPricingService::BASIS_HALF_MONTHLY => [
                'سعر نص الشهر',
                (int) ($contract->frozen_half_monthly_rate
                    ?: round($daily * ContractPricingService::DEFAULT_HALF_MONTHLY_FACTOR)),
            ],
            default => ['السعر اليومي', $daily],
        };
    }

    /**
     * @return array{
     *   rent: int,
     *   contract_charges: int,
     *   violations: Collection<int, Violation>,
     *   violations_count: int,
     *   violations_billable: int,
     *   total: int,
     *   paid_net: int,
     *   remaining: int
     * }
     */
    private function totals(Contract $contract): array
    {
        $rent = $this->ledgerMath->trustStoredPeriodRent($contract)
            ? (int) $contract->period_rent
            : $this->ledgerMath->livePeriodRent($contract);
        $contractCharges = $this->ledgerMath->chargeTotal($contract);
        $violations = $this->contractViolations($contract);
        $violationsBillable = (int) $violations
            ->filter(fn (Violation $v) => $this->isViolationBillable($v))
            ->sum(fn (Violation $v) => (int) $v->amount);
        $total = $contractCharges + $violationsBillable;

        $payments = Payment::query()
            ->where('link_type', 'contract')
            ->where('link_id', $contract->id)
            ->get(['amount', 'direction']);
        $paidIn = (int) $payments->where('direction', 'in')->sum('amount');
        $paidOut = (int) $payments->where('direction', 'out')->sum('amount');
        $paidNet = $paidIn - $paidOut;
        $remaining = max(0, $total - $paidNet);

        return [
            'rent' => $rent,
            'contract_charges' => $contractCharges,
            'violations' => $violations,
            'violations_count' => $violations->count(),
            'violations_billable' => $violationsBillable,
            'total' => $total,
            'paid_net' => $paidNet,
            'remaining' => $remaining,
        ];
    }

    /**
     * @return Collection<int, Violation>
     */
    private function contractViolations(Contract $contract): Collection
    {
        return Violation::query()
            ->where('contract_id', $contract->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    private function isViolationBillable(Violation $violation): bool
    {
        return ! in_array((string) $violation->status, ['confirmed', 'paid'], true);
    }

    /**
     * @param  Collection<int, Violation>  $violations
     */
    private function violationRowsHtml(Collection $violations): string
    {
        if ($violations->isEmpty()) {
            return '';
        }

        $html = '';
        foreach ($violations as $index => $violation) {
            $n = $index + 1;
            $date = $violation->occurred_at
                ? CivilDate::formatDisplay($violation->occurred_at->timezone(config('app.timezone')))
                : '—';
            $amount = (int) $violation->amount;
            $billable = $this->isViolationBillable($violation);
            $statusHint = $billable ? '' : ' · مسدد';
            $location = trim((string) ($violation->location ?? ''));
            $desc = 'مخالفة مرورية #'.$n.' · '.$date.$statusHint
                .($location !== '' ? ' · '.$location : '');
            $amountCell = $billable
                ? e($this->moneyPlain($amount))
                : '— <span style="color:var(--muted);font-weight:500">('.e($this->moneyPlain($amount)).' مسدد)</span>';
            $descEsc = e($desc);
            $html .= <<<HTML
          <tr>
            <td class="desc">{$descEsc}</td>
            <td class="num" data-label="الكمية">1</td>
            <td class="num" data-label="السعر">—</td>
            <td class="num" data-label="الإجمالي">{$amountCell}</td>
          </tr>
HTML;
        }

        return $html;
    }

    private function rentalDays(Contract $contract): int
    {
        if ((int) ($contract->approved_days ?? 0) > 0) {
            return (int) $contract->approved_days;
        }
        $start = $contract->starts_at;
        $end = $contract->actual_ends_at ?? $contract->planned_ends_at;
        if (! $start instanceof CarbonInterface || ! $end instanceof CarbonInterface) {
            return 0;
        }

        return $this->pricing->countRentalDays($start, $end);
    }

    private function invoiceNumber(string $contractNumber): string
    {
        $raw = preg_replace('/^C-?/i', '', $contractNumber) ?: $contractNumber;

        return 'INV-'.$raw;
    }

    private function nationalityOf(mixed $nationality, mixed $nationalId): string
    {
        $n = trim((string) $nationality);
        if ($n !== '') {
            return $n;
        }
        if (filled($nationalId)) {
            return 'مصري';
        }

        return '';
    }

    private function logoSrc(?string $logoPath): string
    {
        $logoPath = is_string($logoPath) ? trim($logoPath) : '';
        if ($logoPath === '') {
            return PublicAppUrl::to('brand/rpm-logo.webp');
        }
        if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
            return $logoPath;
        }

        return PublicAppUrl::to(ltrim($logoPath, '/'));
    }

    private function whenLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof CarbonInterface) {
            return $value->timezone(config('app.timezone'))->format('d/m/Y H:i');
        }

        try {
            return \Carbon\CarbonImmutable::parse((string) $value)
                ->timezone(config('app.timezone'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return CivilDate::formatDisplay($value, '');
        }
    }

    private function dash(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }

    private function moneyPlain(int $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }
}
