/** Shared print shell — near-pixel clone of the RPM paper rental contract. */
export function shellRentalContractHtml(body: string, title = 'عقد إيجار سيارة'): string {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${escapeTitle(title)}</title>
<style>
  @page { size: A4; margin: 6mm 5.5mm; }
  * { box-sizing: border-box; }
  body{
    font-family: 'Segoe UI', Tahoma, 'Arial', sans-serif;
    padding: 0; margin: 0;
    color: #111;
    font-size: 10px;
    line-height: 1.35;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .rpm-paper-v2{
    --rpm-red: #c40000;
    --rpm-red-dark: #7a0000;
    --rpm-black: #141414;
    max-width: 199mm;
    margin: 0 auto;
  }

  /* —— Header: three floating badges on white —— */
  .rpm-lc-head{
    display: grid;
    grid-template-columns: 1.05fr 1.45fr 1.05fr;
    gap: 3mm;
    align-items: stretch;
    margin: 0 0 3.5mm;
    background: transparent;
  }
  .rpm-lc-badge{
    background: linear-gradient(180deg, #2f2f2f 0%, #0a0a0a 100%);
    color: #fff;
    border-radius: 999px;
    border: 2px solid var(--rpm-red);
    box-shadow: 0 1px 0 #000, 0 2px 5px rgba(0,0,0,.35);
    padding: 2.2mm 3.5mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    min-height: 15.5mm;
  }
  .rpm-lc-badge--brand{ padding-block: 1.6mm; }
  .rpm-lc-badge--title{
    border-width: 2px;
    padding-inline: 5mm;
  }
  .rpm-lc-badge--contact{ align-items: stretch; gap: 1.4mm; }
  .rpm-lc-logo{
    width: 22mm; height: auto; max-height: 9mm;
    object-fit: contain;
    display: block;
  }
  .rpm-lc-brand-mark{
    font-size: 18px; font-weight: 900; letter-spacing: .04em;
    line-height: 1; font-style: italic;
  }
  .rpm-lc-brand-mark span{ color: var(--rpm-red); }
  .rpm-lc-swoosh{
    width: 18mm; height: 2px; margin: 1mm 0 1.2mm;
    background: linear-gradient(90deg, transparent, var(--rpm-red), transparent);
    border-radius: 2px;
  }
  .rpm-lc-tagline{ font-size: 8.5px; font-weight: 700; color: #f5f5f5; }
  .rpm-lc-title-row{
    display: inline-flex; align-items: center; gap: 2.5mm;
    font-size: 15px; font-weight: 900; white-space: nowrap;
  }
  .rpm-lc-ico-handshake{ width: 7mm; height: 7mm; flex: 0 0 auto; }
  .rpm-lc-signer-short{ font-size: 11px; font-weight: 800; text-align: center; }
  .rpm-lc-phone-pill{
    display: flex; align-items: center; justify-content: center; gap: 1.5mm;
    background: linear-gradient(180deg, #e10600 0%, #9b0000 100%);
    border-radius: 999px;
    padding: 1.1mm 2.5mm;
    font-size: 10px; font-weight: 800;
    border: 1px solid rgba(255,255,255,.35);
    direction: ltr;
  }
  .rpm-lc-ico-phone{ width: 3.4mm; height: 3.4mm; }

  .rpm-lc-meta{
    display: none; /* paper original has no contract-number strip */
  }

  /* —— Content boxes —— */
  .rpm-lc-block{
    position: relative;
    border: 1.4px solid #000;
    border-radius: 3mm;
    padding: 3.2mm 3.8mm 5.5mm;
    margin: 0 0 3.2mm;
    background: #fff;
  }
  .rpm-lc-block p{ margin: 1.4mm 0; font-weight: 700; font-size: 10px; }
  .rpm-lc-block--icon{ padding-inline-start: 18mm; }
  .rpm-lc-float-ico{
    position: absolute;
    inset-inline-start: 2.5mm;
    top: 50%;
    transform: translateY(-50%);
    width: 13mm; height: 13mm;
    border-radius: 50%;
    display: grid; place-items: center;
  }
  .rpm-lc-float-ico--car{
    background: #111;
    border: 2.2px solid var(--rpm-red);
    box-shadow: inset 0 0 0 1px #111;
  }
  .rpm-lc-float-ico--cal{
    background: linear-gradient(180deg, #e10600, #8a0000);
    border: 1px solid #5c0000;
  }
  .rpm-lc-float-ico svg{ width: 7.5mm; height: 7.5mm; }

  .rpm-lc-party-tag{
    position: absolute;
    left: 4mm; /* visual left of page (LTR left) */
    bottom: -3.2mm;
    background: linear-gradient(180deg, #e10600 0%, #7a0000 100%);
    color: #fff;
    border-radius: 999px;
    padding: 1.2mm 4mm;
    font-size: 9px;
    font-weight: 800;
    box-shadow: 0 1px 3px rgba(0,0,0,.35);
    white-space: nowrap;
    z-index: 2;
  }

  .fill{
    display: inline-block;
    min-width: 5em;
    min-height: 1em;
    padding: 0 1.5mm;
    border-bottom: 1px dotted #222;
    font-weight: 800;
  }
  .fill.wide{ min-width: 14em; }
  .fill.mid{ min-width: 8em; }

  /* —— Terms: pill + spear wings —— */
  .rpm-lc-terms-wrap{ margin: 4mm 0 2mm; }
  .rpm-lc-terms-banner{
    display: flex; align-items: center; gap: 0;
    margin: 0 0 2.5mm;
    background: transparent;
    padding: 0;
    border-radius: 0;
    text-align: center;
  }
  .rpm-lc-terms-banner::before,
  .rpm-lc-terms-banner::after{
    content: '';
    flex: 1 1 auto;
    height: 5.5mm;
    background: var(--rpm-red);
    clip-path: polygon(0 40%, 92% 0, 100% 50%, 92% 100%, 0 60%);
  }
  .rpm-lc-terms-banner::after{
    clip-path: polygon(8% 0, 100% 40%, 100% 60%, 8% 100%, 0 50%);
  }
  .rpm-lc-terms-banner > span{
    flex: 0 0 auto;
    background: linear-gradient(180deg, #e10600, #9b0000);
    color: #fff;
    font-size: 13px;
    font-weight: 900;
    padding: 1.6mm 8mm;
    border-radius: 999px;
    border: 1px solid #5c0000;
    box-shadow: 0 1px 3px rgba(0,0,0,.3);
    margin-inline: 1.5mm;
  }
  .rpm-lc-terms{
    margin: 0; padding: 0; list-style: none;
  }
  .rpm-lc-terms li{
    margin: 0 0 1.8mm;
    padding: 0;
    text-align: justify;
    font-size: 9.4px;
    font-weight: 700;
    line-height: 1.38;
  }
  .rpm-lc-band{
    display: inline-block;
    background: linear-gradient(180deg, #e10600, #7a0000);
    color: #fff;
    border-radius: 999px;
    padding: 0.6mm 3.2mm;
    margin-inline-end: 1.8mm;
    font-size: 8.5px;
    font-weight: 900;
    box-shadow: 0 1px 2px rgba(0,0,0,.28);
    vertical-align: baseline;
  }
  .rpm-lc-extra-terms .terms:empty,
  .rpm-lc-extra-terms:has(.terms:empty){ display: none; }
  .rpm-lc-extra-terms .terms{
    white-space: pre-wrap;
    margin-top: 2mm;
    font-size: 9px;
    border-top: 1px dashed #999;
    padding-top: 2mm;
  }

  /* —— Footer signatures —— */
  .rpm-lc-sign{
    display: grid;
    grid-template-columns: 1fr 34mm 1fr;
    gap: 3mm;
    align-items: start;
    margin-top: 5mm;
  }
  .rpm-lc-sign-col{
    position: relative;
    border: 1.4px solid #000;
    border-radius: 3mm;
    padding: 5mm 3mm 3mm;
    min-height: 28mm;
  }
  .rpm-lc-sign-col .rpm-lc-party-tag{
    position: absolute;
    left: 50%;
    bottom: auto;
    top: -3.2mm;
    transform: translateX(-50%);
  }
  .rpm-lc-sign-col p{ margin: 1.4mm 0; font-weight: 700; font-size: 9.5px; }
  .rpm-lc-sign-line{ margin-top: 5mm !important; }

  .rpm-lc-stamp{
    display: flex; align-items: center; justify-content: center;
    padding-top: 1mm;
  }
  .rpm-lc-stamp-ring{
    width: 32mm; height: 32mm;
    border-radius: 50%;
    border: 2.2px double #111;
    box-shadow: inset 0 0 0 1.2px #111;
    color: #111;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; gap: 0.4mm; padding: 2mm;
    font-size: 6.5px; font-weight: 800;
    background: #fff;
  }
  .rpm-lc-stamp-ring .stamp-rpm{
    font-size: 11px; font-weight: 900; letter-spacing: .03em; line-height: 1;
  }
  .rpm-lc-stamp-ring .stamp-en{
    font-size: 6px; font-weight: 800; letter-spacing: .08em;
    text-transform: uppercase;
  }
  .rpm-lc-stamp-ring .stamp-stars{ letter-spacing: 0.4mm; font-size: 7px; line-height: 1; }
  .rpm-lc-stamp-ring img{ width: 9mm; height: auto; object-fit: contain; }
  .foot{ margin-top: 2mm; color: #666; font-size: 8px; text-align: center; }

  @media print{
    body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style></head><body>${body}</body></html>`
}

function escapeTitle(title: string) {
  return title.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
}

/** True when the rendered body looks like the paper-form legal contract. */
export function isRentalContractBody(html: string) {
  return html.includes('rpm-legal-contract') || html.includes('rpm-paper-v2') || html.includes('شروط التعاقد')
}
