/** Shared print shell for owner–center vehicle operation agreements. */
export function shellOwnerAgreementHtml(body: string, title = 'عقد إدارة وتشغيل مركبة'): string {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${title}</title>
<style>
  @page { size: A4; margin: 14mm 12mm; }
  body{font-family:Tahoma,'Segoe UI',Arial,sans-serif;padding:0;margin:0;color:#0f172a;font-size:13px;line-height:1.75}
  h1{margin:0 0 4px;font-size:20px} h2{margin:18px 0 8px;font-size:15px} h3{margin:14px 0 6px;font-size:14px}
  .muted{color:#64748b;font-size:12px}
  table{width:100%;border-collapse:collapse;margin-top:10px}
  td,th{border:1px solid #cbd5e1;padding:7px 8px;text-align:start;vertical-align:top}
  th{background:#f8fafc;width:30%;font-weight:700}
  .foot{margin-top:20px;color:#64748b;font-size:11px}
  .terms{margin-top:8px;white-space:pre-wrap;font-size:13px;line-height:1.75}
  .print-head{display:flex;align-items:center;gap:16px;margin-bottom:14px;border-bottom:2px solid #0f172a;padding-bottom:12px}
  .print-logo{width:88px;height:auto;object-fit:contain}
  .contract-title{text-align:center;margin:12px 0 6px;font-size:18px;font-weight:800}
  .contract-sub{text-align:center;margin:0 0 16px;color:#475569;font-size:12px}
  .article{margin:12px 0 10px}
  .article h3{border-inline-start:3px solid #0f172a;padding-inline-start:8px}
  .article p,.article li{margin:6px 0}
  .article ol{padding-inline-start:1.4rem;margin:6px 0}
  .page-break{break-before:page;page-break-before:always}
  .sign-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:28px}
  .sign-box{border:1px solid #cbd5e1;border-radius:8px;padding:14px;min-height:120px}
  .sign-box h4{margin:0 0 8px;font-size:13px}
  .sign-line{margin-top:36px;border-top:1px solid #94a3b8;padding-top:6px;font-size:12px;color:#64748b}
  .preamble{margin:10px 0 14px}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:12px}
  @media print{
    body{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .page-break{break-before:page;page-break-before:always}
  }
</style></head><body>${body}</body></html>`
}
