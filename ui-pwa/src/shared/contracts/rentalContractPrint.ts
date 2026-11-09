/** Shared print shell for the RPM paper-form rental contract. */
export function shellRentalContractHtml(body: string, title = 'عقد إيجار سيارة'): string {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${escapeTitle(title)}</title>
<style>
  @page { size: A4; margin: 10mm 9mm; }
  * { box-sizing: border-box; }
  body{
    font-family: Tahoma,'Segoe UI',Arial,sans-serif;
    padding:0; margin:0; color:#111;
    font-size:12.2px; line-height:1.55;
    background:#fff;
  }
  .rpm-legal-contract{ max-width:190mm; margin:0 auto; }
  .rpm-lc-head{
    display:grid;
    grid-template-columns: 1.1fr 1.4fr 1.1fr;
    gap:8px;
    align-items:center;
    background:#0b0b0b;
    color:#fff;
    padding:10px 12px;
    border-radius:4px;
    margin-bottom:10px;
  }
  .rpm-lc-brand{ display:flex; flex-direction:column; align-items:flex-start; gap:4px; }
  .rpm-lc-logo{ width:78px; height:auto; object-fit:contain; filter:none; }
  .rpm-lc-tagline{ font-size:11px; color:#f3f3f3; }
  .rpm-lc-title-pill{
    justify-self:center;
    display:inline-flex; align-items:center; gap:8px;
    background:#111; color:#fff;
    border:2px solid #e10600;
    border-radius:999px;
    padding:8px 18px;
    font-weight:800; font-size:16px;
    white-space:nowrap;
  }
  .rpm-lc-title-icon{ font-size:15px; }
  .rpm-lc-contact{ text-align:start; font-size:12px; line-height:1.45; }
  .rpm-lc-signer-short{ font-weight:700; }
  .rpm-lc-phone{ color:#ff4d4d; font-weight:700; margin-top:2px; }
  .rpm-lc-meta{ margin:0 0 8px; color:#444; font-size:11px; }
  .rpm-lc-block{
    border:1px solid #d4d4d4;
    border-radius:6px;
    padding:8px 10px;
    margin:0 0 8px;
    position:relative;
  }
  .rpm-lc-block p{ margin:4px 0; }
  .rpm-lc-party-tag{
    display:inline-block;
    margin-top:6px;
    background:#e10600;
    color:#fff;
    border-radius:999px;
    padding:3px 12px;
    font-size:11px;
    font-weight:700;
  }
  .fill{
    display:inline-block;
    min-width:4.5em;
    min-height:1.05em;
    padding:0 4px;
    border-bottom:1px dotted #222;
    font-weight:700;
  }
  .fill.wide{ min-width:12em; }
  .rpm-lc-terms-banner{
    margin:10px 0 6px;
    background:#e10600;
    color:#fff;
    text-align:center;
    font-size:15px;
    font-weight:800;
    padding:6px 10px;
    border-radius:4px;
  }
  .rpm-lc-terms{
    margin:0;
    padding:0;
    list-style:none;
  }
  .rpm-lc-terms li{
    margin:0 0 6px;
    padding:0;
    text-align:justify;
  }
  .rpm-lc-band{
    display:inline-block;
    background:#e10600;
    color:#fff;
    border-radius:999px;
    padding:1px 10px;
    margin-inline-end:6px;
    font-size:11px;
    font-weight:800;
  }
  .rpm-lc-extra-terms .terms:empty,
  .rpm-lc-extra-terms:has(.terms:empty){ display:none; }
  .rpm-lc-extra-terms .terms{
    white-space:pre-wrap;
    margin-top:8px;
    font-size:12px;
    border-top:1px dashed #ccc;
    padding-top:8px;
  }
  .rpm-lc-sign{
    display:grid;
    grid-template-columns:1fr auto 1fr;
    gap:12px;
    align-items:start;
    margin-top:14px;
  }
  .rpm-lc-sign-col h4{
    margin:0 0 6px;
    font-size:13px;
    color:#e10600;
  }
  .rpm-lc-sign-col p{ margin:3px 0; }
  .rpm-lc-sign-line{ margin-top:18px !important; }
  .rpm-lc-stamp{ display:flex; align-items:center; justify-content:center; padding-top:4px; }
  .rpm-lc-stamp-ring{
    width:110px; height:110px;
    border:3px double #e10600;
    border-radius:50%;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center; gap:2px; padding:8px;
    color:#e10600; font-size:9px; font-weight:700;
  }
  .rpm-lc-stamp-ring img{ width:42px; height:auto; object-fit:contain; }
  .foot{ margin-top:10px; color:#666; font-size:10px; }
  @media print{
    body{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style></head><body>${body}</body></html>`
}

function escapeTitle(title: string) {
  return title.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
}

/** True when the rendered body looks like the paper-form legal contract. */
export function isRentalContractBody(html: string) {
  return html.includes('rpm-legal-contract') || html.includes('شروط التعاقد')
}
