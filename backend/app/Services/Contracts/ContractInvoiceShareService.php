<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Services\Media\PublicMediaLinkService;
use App\Support\CivilDate;
use App\Support\DepositCurrency;
use App\Support\PublicAppUrl;
use Carbon\CarbonInterface;

/**
 * تجهيز فاتورة داخلية عامة موقّعة للمشاركة عبر واتساب (مثل بيان المخالفات).
 */
class ContractInvoiceShareService
{
    public function __construct(
        private readonly PublicMediaLinkService $links,
        private readonly ContractLedgerMath $ledgerMath,
    ) {}

    /**
     * @return array{share_url: string, message_text: string, customer_phone: ?string, expires_in_days: int, invoice_number: string}
     */
    public function prepare(Contract $contract): array
    {
        $contract->loadMissing(['customer', 'vehicle', 'deposit', 'inspections']);
        $shareUrl = $this->links->temporaryContractInvoiceShareUrl((int) $contract->id);
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
        $lines[] = 'الإجمالي: '.$this->moneyPlain($totals['total']).' ج.م.';
        if ($totals['paid_net'] > 0) {
            $lines[] = 'المدفوع: '.$this->moneyPlain($totals['paid_net']).' ج.م.';
        }
        $lines[] = 'المتبقي: '.$this->moneyPlain($totals['remaining']).' ج.م.';
        $lines[] = 'الرابط صالح لمدة '.PublicMediaLinkService::DEFAULT_TTL_DAYS.' يوماً.';

        return [
            'share_url' => $shareUrl,
            'message_text' => implode("\n", $lines),
            'customer_phone' => $customerPhone,
            'expires_in_days' => PublicMediaLinkService::DEFAULT_TTL_DAYS,
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
        $daily = (int) ($contract->frozen_daily_rate ?? 0);
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

        $customerName = e((string) ($customer?->full_name ?? ''));
        $customerPhone = e((string) ($customer?->phone ?? ''));
        $customerAddress = e((string) ($customer?->address ?? ''));
        $idOrPassport = e((string) ($customer?->national_id ?: $customer?->passport_number ?: ($ops['print_tax_info'] ?? '')));
        $idExpiryRaw = $customer?->national_id_expires_on ?? $customer?->license_expires_on;
        $idExpiry = e($idExpiryRaw ? CivilDate::formatDisplay($idExpiryRaw, '') : '');
        $nationalityEsc = e($nationality);
        $vehicleName = e(trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')));
        $vehicleYear = e($this->dash($vehicle?->year));
        $vehiclePlate = e((string) ($vehicle?->plate ?? ''));
        $vehicleColor = e((string) ($vehicle?->color ?? ''));
        $outAtLabel = e($this->whenLabel($outAt));
        $inAtLabel = e($this->whenLabel($inAt));
        $odoOutLabel = $odoOut != null ? e(number_format((int) $odoOut, 0, '.', ',')) : '';
        $odoInLabel = $odoIn != null ? e(number_format((int) $odoIn, 0, '.', ',')) : '';

        $deliveryRow = $hasDelivery
            ? '<tr><td class="desc">خدمة تسليم واستلام السيارة'
                .($deliveryNotes !== '' ? ' · '.e($deliveryNotes) : '')
                .'</td><td class="num">1</td><td class="num">—</td><td class="num">'
                .e($this->moneyPlain($delivery)).'</td></tr>'
            : '<tr><td class="desc">خدمة تسليم واستلام السيارة</td><td class="num"></td><td class="num"></td><td class="num"></td></tr>';

        $otherRow = ($other > 0 || $damage > 0)
            ? '<tr><td class="desc">رسوم أخرى / خدمات إضافية'
                .($damage > 0 ? ' · أضرار' : '')
                .($otherLabel !== '' ? ' · '.e($otherLabel) : '')
                .'</td><td class="num">1</td><td class="num">—</td><td class="num">'
                .e($this->moneyPlain($other + $damage)).'</td></tr>'
            : '<tr><td class="desc">رسوم أخرى / خدمات إضافية</td><td class="num"></td><td class="num"></td><td class="num"></td></tr>';

        $depositCell = $deposit > 0
            ? e(number_format($deposit, 0, '.', ',').' '.$depositCurrency)
            : '';
        $ttl = PublicMediaLinkService::DEFAULT_TTL_DAYS;
        $daysCell = $days > 0 ? (string) $days : '';
        $dailyCell = $this->moneyCell($daily);
        $rentCell = $this->moneyCell($totals['rent']);
        $depositQty = $this->qtyCell($deposit);
        $prepaidQtyCell = $this->qtyCell($prepaidQty);
        $prepaidAmtCell = $this->moneyCell($prepaidAmt);
        $excessQtyCell = $this->qtyCell($excessQty);
        $excessAmtCell = $this->moneyCell($excessAmt);
        $totalCell = $this->moneyCell($totals['total']);
        $paidCell = $this->moneyCell($totals['paid_net']);
        $remainingCell = $this->moneyCell($totals['remaining']);

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <title>فاتورة {$invNo} — {$centerEsc}</title>
  <style>
    @page { size: A4; margin: 10mm 9mm; }
    * { box-sizing: border-box; }
    html{ margin:0; }
    body{
      margin:0; padding:12px 10px calc(16px + env(safe-area-inset-bottom, 0px));
      background:#f4f4f5; color:#111;
      font-family: Tahoma,'Segoe UI',Arial,sans-serif;
      font-size: 11px; line-height: 1.45;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .sheet{
      background:#fff; border-radius:12px; padding:14px 12px 18px;
      box-shadow: 0 8px 28px rgba(24,24,27,.08);
      max-width: 210mm; margin: 0 auto;
    }
    .share-banner{
      margin: 0 auto 10px; max-width: 210mm; padding: 8px 12px;
      background:#18181b; color:#fff; border-radius:10px;
      font-size:12px; text-align:center;
    }
    .rpm-invoice-v1{ --inv-navy:#0b1f3a; --inv-red:#c40000; width:100%; max-width:192mm; margin:0 auto; }
    .inv-head{
      display:flex; justify-content:space-between; align-items:flex-start; gap:12px;
      margin-bottom:10px; border-bottom:2px solid var(--inv-navy); padding-bottom:8px;
      direction: ltr;
    }
    .inv-brand{ display:flex; align-items:center; gap:10px; }
    .inv-title-block, .inv-meta, .inv-meta .lbl { direction: rtl; text-align: right; }
    .inv-brand img{ width:64px; height:auto; object-fit:contain; }
    .inv-brand-text{ display:flex; flex-direction:column; line-height:1.15; }
    .inv-brand-text strong{ font-size:15px; color:var(--inv-navy); }
    .inv-brand-text span{ font-size:11px; color:var(--inv-red); font-weight:800; letter-spacing:.06em; border-bottom:2px solid var(--inv-red); padding-bottom:1px; width:max-content; }
    .inv-title-block{ text-align:start; min-width:42%; }
    .inv-title-block h1{ margin:0 0 6px; font-size:22px; color:#111; letter-spacing:.02em; }
    .inv-meta{ font-size:11px; }
    .inv-meta > div{ margin:2px 0; display:flex; gap:6px; flex-wrap:wrap; }
    .inv-meta .lbl{ color:#333; font-weight:700; }
    .inv-meta .val{ border-bottom:1px dotted #333; min-width:7em; padding:0 4px; font-weight:800; }
    .inv-section{ margin:10px 0; }
    .inv-section-title{
      background: var(--inv-navy); color:#fff; font-weight:800; font-size:12px;
      padding:5px 10px; text-align:center;
    }
    .inv-grid{ width:100%; border-collapse:collapse; table-layout:fixed; }
    .inv-grid th, .inv-grid td{
      border:1px solid #222; padding:5px 7px; vertical-align:top; font-size:11px;
    }
    .inv-grid th{ width:18%; background:#f3f5f8; font-weight:800; white-space:nowrap; }
    .inv-finance{ position:relative; }
    .inv-watermark{
      position:absolute; inset:28% 10% 18%;
      display:flex; align-items:center; justify-content:center;
      font-size:42px; font-weight:900; color:rgba(11,31,58,.06);
      pointer-events:none; transform:rotate(-18deg); letter-spacing:.04em;
    }
    .inv-finance-table{ width:100%; border-collapse:collapse; position:relative; z-index:1; }
    .inv-finance-table th, .inv-finance-table td{
      border:1px solid #222; padding:6px 7px; font-size:11px;
    }
    .inv-finance-table thead th{ background:var(--inv-navy); color:#fff; font-weight:800; }
    .inv-finance-table .desc{ text-align:start; }
    .inv-finance-table .num{ text-align:center; font-variant-numeric:tabular-nums; width:18%; }
    .inv-finance-table tr.totals td{ background:#f7f8fa; font-weight:700; }
    .inv-note{ margin:6px 0 0; font-size:10px; color:#555; }
    .inv-terms{
      margin-top:12px; background:#f2f2f2; border:1px solid #cfcfcf; border-radius:4px; padding:8px 12px;
    }
    .inv-terms h3{ margin:0 0 6px; font-size:12px; }
    .inv-terms ol{ margin:0; padding-inline-start:1.3rem; }
    .inv-terms li{ margin:3px 0; }
    .inv-sign{ display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:22px; }
    .inv-sign strong{ display:block; margin-bottom:28px; font-size:11px; }
    .sign-line{ border-bottom:1px solid #333; height:1px; }
    .inv-foot{
      display:flex; justify-content:space-between; gap:12px; margin-top:16px;
      font-size:10px; color:#444; border-top:1px solid #ddd; padding-top:6px;
    }
    .inv-print-footer{ margin-top:6px; font-size:10px; color:#666; text-align:center; }
    @media print{
      body{ background:#fff; padding:0; }
      .share-banner{ display:none; }
      .sheet{ box-shadow:none; border-radius:0; padding:0; }
    }
  </style>
</head>
<body>
  <p class="share-banner">فاتورة مؤقتة عبر واتساب · صالحة {$ttl} يوماً · بلا تسجيل دخول</p>
  <div class="sheet">
<article class="rpm-invoice-v1">
  <header class="inv-head">
    <div class="inv-brand">
      <img src="{$logoEsc}" alt="{$centerEsc}" />
      <div class="inv-brand-text">
        <strong>{$centerEsc}</strong>
        <span>RENT A CAR</span>
      </div>
    </div>
    <div class="inv-title-block">
      <h1>INVOICE / فاتورة</h1>
      <div class="inv-meta">
        <div><span class="lbl">Invoice # / رقم الفاتورة:</span> <span class="val" dir="ltr">{$invNo}</span></div>
        <div><span class="lbl">Date / التاريخ:</span> <span class="val" dir="ltr">{$invDate}</span></div>
        <div><span class="lbl">Agreement # / رقم العقد:</span> <span class="val" dir="ltr">{$numberEsc}</span></div>
      </div>
    </div>
  </header>

  <section class="inv-section">
    <div class="inv-section-title">بيانات المستأجر / Tenant Details</div>
    <table class="inv-grid">
      <tr>
        <th>اسم المستأجر:</th><td>{$customerName}</td>
        <th>سجل تجاري:</th><td dir="ltr">{$printCr}</td>
      </tr>
      <tr>
        <th>رقم الهاتف:</th><td dir="ltr">{$customerPhone}</td>
        <th>الجنسية:</th><td>{$nationalityEsc}</td>
      </tr>
      <tr>
        <th>بطاقة ضريبية / قومي:</th><td dir="ltr">{$idOrPassport}</td>
        <th>تاريخ انتهائها:</th><td dir="ltr">{$idExpiry}</td>
      </tr>
      <tr>
        <th>العنوان:</th><td colspan="3">{$customerAddress}</td>
      </tr>
    </table>
  </section>

  <section class="inv-section">
    <div class="inv-section-title">بيانات السيارة / Vehicle Details</div>
    <table class="inv-grid">
      <tr>
        <th>نوع السيارة / الموديل:</th><td>{$vehicleName}</td>
        <th>سنة الصنع:</th><td dir="ltr">{$vehicleYear}</td>
      </tr>
      <tr>
        <th>رقم اللوحة:</th><td>{$vehiclePlate}</td>
        <th>اللون:</th><td>{$vehicleColor}</td>
      </tr>
      <tr>
        <th>تاريخ الخروج:</th><td dir="ltr">{$outAtLabel}</td>
        <th>عداد الخروج (كم):</th><td dir="ltr">{$odoOutLabel}</td>
      </tr>
      <tr>
        <th>تاريخ الدخول:</th><td dir="ltr">{$inAtLabel}</td>
        <th>عداد الدخول (كم):</th><td dir="ltr">{$odoInLabel}</td>
      </tr>
    </table>
  </section>

  <section class="inv-section inv-finance">
    <div class="inv-section-title">تفاصيل الحساب المالية / Financial Summary</div>
    <div class="inv-watermark" aria-hidden="true">{$centerEsc}</div>
    <table class="inv-finance-table">
      <thead>
        <tr>
          <th>البيان / Description</th>
          <th>الكمية / الأيام</th>
          <th>السعر اليومي</th>
          <th>الإجمالي</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="desc">إيجار سيارة (Rental Days)</td>
          <td class="num">{$daysCell}</td>
          <td class="num">{$dailyCell}</td>
          <td class="num">{$rentCell}</td>
        </tr>
        <tr>
          <td class="desc">تأمين مؤقت (Insurance Deposit)</td>
          <td class="num">{$depositQty}</td>
          <td class="num">—</td>
          <td class="num">{$depositCell}</td>
        </tr>
        <tr>
          <td class="desc">كم زيادة مدفوعة مقدماً (Prepaid Extra KM)</td>
          <td class="num">{$prepaidQtyCell}</td>
          <td class="num">—</td>
          <td class="num">{$prepaidAmtCell}</td>
        </tr>
        <tr>
          <td class="desc">زيادة إضافية عند الاستلام (Extra KM beyond prepaid)</td>
          <td class="num">{$excessQtyCell}</td>
          <td class="num">—</td>
          <td class="num">{$excessAmtCell}</td>
        </tr>
        {$deliveryRow}
        {$otherRow}
        <tr class="totals">
          <td colspan="3">المبلغ الإجمالي / Total Amount</td>
          <td class="num"><strong>{$totalCell}</strong></td>
        </tr>
        <tr class="totals">
          <td colspan="3">المبلغ المدفوع / Amount Paid</td>
          <td class="num">{$paidCell}</td>
        </tr>
        <tr class="totals">
          <td colspan="3">المتبقي / Remaining Balance</td>
          <td class="num"><strong>{$remainingCell}</strong></td>
        </tr>
      </tbody>
    </table>
    <p class="inv-note">ملاحظة: التأمين المؤقت خارج إجمالي الإيجار ويُسوَّى عند الاستلام.</p>
  </section>

  <section class="inv-terms">
    <h3>الشروط والأحكام العامة:</h3>
    <ol>
      <li>يتعهد المستأجر بإعادة السيارة بنفس الحالة التي استلمها عليها وفي الوقت المحدد بالعقد.</li>
      <li>المستأجر مسؤول عن كافة المخالفات المرورية التي تسجل على السيارة خلال فترة الإيجار.</li>
      <li>يمنع استخدام السيارة في غير الأغراض المخصصة لها أو قيادتها من غير الأشخاص المصرح لهم.</li>
    </ol>
  </section>

  <footer class="inv-sign">
    <div>
      <strong>توقيع المستأجر / Tenant Signature</strong>
      <div class="sign-line"></div>
    </div>
    <div>
      <strong>توقيع المستلم / المعرض / Authorized Signature</strong>
      <div class="sign-line"></div>
    </div>
  </footer>

  <div class="inv-foot">
    <span>Page 1 of 1</span>
    <span>{$centerEsc} - Official Receipt &amp; Agreement Summary</span>
  </div>
  {$printFooterHtml}
</article>
  </div>
</body>
</html>
HTML;
    }

    /**
     * @return array{rent: int, total: int, paid_net: int, remaining: int}
     */
    private function totals(Contract $contract): array
    {
        $rent = $this->ledgerMath->trustStoredPeriodRent($contract)
            ? (int) $contract->period_rent
            : $this->ledgerMath->livePeriodRent($contract);
        $total = $this->ledgerMath->chargeTotal($contract);

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
            'total' => $total,
            'paid_net' => $paidNet,
            'remaining' => $remaining,
        ];
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
        $seconds = $end->getTimestamp() - $start->getTimestamp();

        return max(1, (int) ceil($seconds / 86_400) ?: 1);
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

    private function moneyCell(int $amount): string
    {
        return $amount ? e($this->moneyPlain($amount)) : '';
    }

    private function qtyCell(int $qty): string
    {
        return $qty > 0 ? (string) $qty : '';
    }
}
