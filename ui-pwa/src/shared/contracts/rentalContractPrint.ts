/** Shared print shell — near-pixel clone of the RPM paper rental contract. */
export function shellRentalContractHtml(body: string, title = 'عقد إيجار سيارة'): string {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${escapeTitle(title)}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900&display=swap');
  @page { size: A4; margin: 5mm 4.5mm; }
  * { box-sizing: border-box; }
  body{
    font-family: 'Cairo', 'Segoe UI', Tahoma, 'Arial', sans-serif;
    padding: 0; margin: 0;
    color: #0d0d0d;
    font-size: 10.2px;
    line-height: 1.34;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .rpm-paper-v2, .rpm-paper-v3{
    --rpm-red: #d40000;
    --rpm-red-mid: #e10600;
    --rpm-red-dark: #8a0000;
    --rpm-black: #0a0a0a;
    --rpm-gray: #ececec;
    max-width: 200mm;
    margin: 0 auto;
    position: relative;
    padding: 1.5mm 1.5mm 3mm;
    border: 1.2px solid #111;
  }
  .rpm-paper-v2::after, .rpm-paper-v3::after{
    content: '';
    display: block;
    height: 2.2px;
    margin-top: 2.5mm;
    background: linear-gradient(90deg, var(--rpm-red-dark), var(--rpm-red-mid), var(--rpm-red-dark));
    border-radius: 2px;
  }

  /* —— Top decorative ribbon (paper original) —— */
  .rpm-lc-ribbon{
    height: 11mm;
    margin: 0 0 2.8mm;
    border-radius: 0 0 46% 46% / 0 0 100% 100%;
    background:
      radial-gradient(130% 110% at 50% -20%, #3a3a3a 0%, #0a0a0a 48%, #000 78%),
      linear-gradient(90deg, transparent 0%, var(--rpm-red) 14%, transparent 32%, transparent 68%, var(--rpm-red) 86%, transparent 100%);
    background-color: #000;
    box-shadow: inset 0 -2.5px 0 var(--rpm-red), 0 2px 4px rgba(0,0,0,.28);
    position: relative;
    overflow: hidden;
  }
  .rpm-lc-ribbon::before,
  .rpm-lc-ribbon::after{
    content: '';
    position: absolute;
    top: 8%;
    width: 42%;
    height: 85%;
    border-radius: 50%;
    border: 2.4px solid rgba(255,255,255,.65);
    border-bottom-color: transparent;
    border-left-color: transparent;
    opacity: .9;
  }
  .rpm-lc-ribbon::before{ left: -4%; transform: rotate(16deg); }
  .rpm-lc-ribbon::after{ right: -4%; transform: scaleX(-1) rotate(16deg); border-color: rgba(255,30,30,.95); border-bottom-color: transparent; border-left-color: transparent; }

  /* —— Header: three floating badges —— */
  .rpm-lc-head{
    display: grid;
    grid-template-columns: 1.08fr 1.55fr 1.08fr;
    gap: 2.4mm;
    align-items: stretch;
    margin: 0 0 3.4mm;
    background: transparent;
  }
  .rpm-lc-badge{
    background: linear-gradient(180deg, #444 0%, #161616 40%, #000 100%);
    color: #fff;
    border-radius: 999px;
    border: 2.5px solid var(--rpm-red);
    box-shadow:
      inset 0 1.2px 0 rgba(255,255,255,.28),
      inset 0 -1px 0 rgba(0,0,0,.55),
      0 2px 6px rgba(0,0,0,.38);
    padding: 2.4mm 3.4mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    min-height: 16.5mm;
    position: relative;
  }
  .rpm-lc-badge::after{
    content: '';
    position: absolute; inset: 1.2px;
    border-radius: inherit;
    border: 1px solid rgba(255,255,255,.28);
    pointer-events: none;
  }
  .rpm-lc-badge--brand{ padding-block: 1.4mm; gap: 0.4mm; }
  .rpm-lc-badge--title{ padding-inline: 4.5mm; border-width: 2.6px; }
  .rpm-lc-badge--contact{ align-items: stretch; gap: 1.2mm; padding-block: 1.6mm; }
  .rpm-lc-logo{
    width: 20mm; height: auto; max-height: 8mm;
    object-fit: contain;
    display: block;
  }
  .rpm-lc-brand-mark{
    position: relative;
    font-size: 22px; font-weight: 900; letter-spacing: .08em;
    line-height: 1; font-style: italic;
    text-shadow: 0 1px 0 #000;
  }
  .rpm-lc-brand-mark .rpm-lc-swoosh-thru{
    position: absolute;
    left: -4%; right: -4%;
    top: 52%;
    height: 2.4px;
    background: linear-gradient(90deg, transparent, var(--rpm-red-mid) 12%, #fff 48%, var(--rpm-red-mid) 88%, transparent);
    transform: translateY(-50%) skewX(-18deg);
    border-radius: 2px;
    box-shadow: 0 0 0 0.5px rgba(0,0,0,.35);
  }
  .rpm-lc-swoosh{
    width: 18mm; height: 2px; margin: 0.8mm 0 0.8mm;
    background: linear-gradient(90deg, transparent, var(--rpm-red-mid), transparent);
    border-radius: 2px;
  }
  .rpm-lc-tagline{ font-size: 8.2px; font-weight: 800; color: #f5f5f5; }
  .rpm-lc-title-row{
    display: inline-flex; align-items: center; gap: 2.4mm;
    font-size: 16.5px; font-weight: 900; white-space: nowrap;
  }
  .rpm-lc-ico-handshake{ width: 8mm; height: 8mm; flex: 0 0 auto; filter: drop-shadow(0 1px 0 rgba(0,0,0,.35)); }
  .rpm-lc-signer-short{ font-size: 12px; font-weight: 800; text-align: center; }
  .rpm-lc-phone-pill{
    display: flex; align-items: center; justify-content: center; gap: 1.6mm;
    background: linear-gradient(180deg, #ff1a1a 0%, #b00000 100%);
    border-radius: 999px;
    padding: 1mm 2.4mm;
    font-size: 10px; font-weight: 800;
    border: 1px solid rgba(255,255,255,.4);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.25);
    direction: ltr;
  }
  .rpm-lc-phone-dot{
    width: 4.6mm; height: 4.6mm; border-radius: 50%;
    background: #fff;
    display: grid; place-items: center;
    flex: 0 0 auto;
  }
  .rpm-lc-ico-phone{ width: 2.8mm; height: 2.8mm; }

  .rpm-lc-meta{ display: none; }

  /* —— Content boxes —— */
  .rpm-lc-block{
    position: relative;
    border: 1.35px solid #000;
    border-radius: 3.2mm;
    padding: 2.8mm 3.6mm 5.2mm;
    margin: 0 0 3.4mm;
    background: #fff;
  }
  .rpm-lc-block p{ margin: 1.2mm 0; font-weight: 700; font-size: 10px; }
  .rpm-lc-block--icon{ padding-inline-start: 17mm; }
  .rpm-lc-float-ico{
    position: absolute;
    inset-inline-start: 2.2mm;
    top: 50%;
    transform: translateY(-50%);
    width: 12.5mm; height: 12.5mm;
    border-radius: 50%;
    display: grid; place-items: center;
  }
  .rpm-lc-float-ico--car{
    background: #111;
    border: 2px solid var(--rpm-red);
    box-shadow: inset 0 0 0 1.2px #fff, 0 1px 3px rgba(0,0,0,.3);
  }
  .rpm-lc-float-ico--cal{
    background: linear-gradient(180deg, #ff1a1a, #8a0000);
    border: 1.4px solid #5c0000;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.3), 0 1px 3px rgba(0,0,0,.28);
  }
  .rpm-lc-float-ico svg{ width: 7.2mm; height: 7.2mm; }

  .rpm-lc-party-tag{
    position: absolute;
    left: 3.5mm;
    bottom: -3mm;
    background: linear-gradient(180deg, #ff1a1a 0%, #9b0000 100%);
    color: #fff;
    border-radius: 999px;
    padding: 1.1mm 3.8mm;
    font-size: 9px;
    font-weight: 800;
    box-shadow: 0 1px 3px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.25);
    white-space: nowrap;
    z-index: 2;
    border: 1px solid #6b0000;
  }

  .fill{
    display: inline-block;
    min-width: 5em;
    min-height: 1em;
    padding: 0 1.4mm;
    border-bottom: 1px dotted #222;
    font-weight: 800;
  }
  .fill.wide{ min-width: 14em; }
  .fill.mid{ min-width: 8em; }

  /* —— Terms banner + gray clause strips —— */
  .rpm-lc-terms-wrap{ margin: 3.5mm 0 1.5mm; }
  .rpm-lc-terms-banner{
    display: flex; align-items: center; gap: 0;
    margin: 0 0 2.4mm;
    background: transparent;
    padding: 0;
    text-align: center;
  }
  .rpm-lc-terms-banner::before,
  .rpm-lc-terms-banner::after{
    content: '';
    flex: 1 1 auto;
    height: 1.6px;
    background: linear-gradient(90deg, transparent, var(--rpm-red-mid) 18%, var(--rpm-red-mid) 82%, transparent);
    position: relative;
  }
  .rpm-lc-terms-banner::before{
    clip-path: polygon(0 45%, 88% 0, 100% 50%, 88% 100%, 0 55%);
    height: 4.8mm;
    background: var(--rpm-red-mid);
  }
  .rpm-lc-terms-banner::after{
    clip-path: polygon(12% 0, 100% 45%, 100% 55%, 12% 100%, 0 50%);
    height: 4.8mm;
    background: var(--rpm-red-mid);
  }
  .rpm-lc-terms-banner > span{
    flex: 0 0 auto;
    background: linear-gradient(180deg, #ff1a1a, #9b0000);
    color: #fff;
    font-size: 13px;
    font-weight: 900;
    padding: 1.5mm 9mm;
    border-radius: 999px;
    border: 1px solid #5c0000;
    box-shadow: 0 1px 3px rgba(0,0,0,.3), inset 0 1px 0 rgba(255,255,255,.25);
    margin-inline: 1.2mm;
    letter-spacing: .02em;
  }
  .rpm-lc-terms{
    margin: 0; padding: 0; list-style: none;
    display: flex; flex-direction: column; gap: 1.1mm;
  }
  .rpm-lc-terms li{
    margin: 0;
    padding: 1.4mm 2.2mm 1.4mm 2.4mm;
    text-align: justify;
    font-size: 9.2px;
    font-weight: 700;
    line-height: 1.36;
    background: var(--rpm-gray);
    border-radius: 2.4mm;
  }
  .rpm-lc-band{
    display: inline-block;
    background: linear-gradient(180deg, #ff1a1a, #8a0000);
    color: #fff;
    border-radius: 999px;
    padding: 0.55mm 3mm;
    margin-inline-end: 1.6mm;
    font-size: 8.3px;
    font-weight: 900;
    box-shadow: 0 1px 2px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.22);
    vertical-align: baseline;
    border: 1px solid #6b0000;
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
    gap: 2.5mm;
    align-items: start;
    margin-top: 5mm;
  }
  .rpm-lc-sign-col{
    position: relative;
    border: 1.35px solid #000;
    border-radius: 3mm;
    padding: 5.2mm 3mm 2.8mm;
    min-height: 27mm;
  }
  .rpm-lc-sign-col .rpm-lc-party-tag{
    position: absolute;
    left: 50%;
    bottom: auto;
    top: -3mm;
    transform: translateX(-50%);
  }
  .rpm-lc-sign-col p{ margin: 1.3mm 0; font-weight: 700; font-size: 9.4px; }
  .rpm-lc-sign-line{ margin-top: 4.5mm !important; }

  .rpm-lc-stamp{
    display: flex; align-items: center; justify-content: center;
    padding-top: 0.5mm;
  }
  .rpm-lc-stamp-ring{
    width: 33mm; height: 33mm;
    border-radius: 50%;
    border: 2.4px double #111;
    box-shadow: inset 0 0 0 1.3px #111;
    color: #111;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; gap: 0.25mm; padding: 1.6mm;
    font-size: 6.2px; font-weight: 800;
    background: #fff;
    position: relative;
  }
  .rpm-lc-stamp-car{ width: 12mm; height: 6mm; margin-bottom: 0.4mm; }
  .rpm-lc-stamp-ring .stamp-rpm{
    font-size: 12px; font-weight: 900; letter-spacing: .04em; line-height: 1;
  }
  .rpm-lc-stamp-ring .stamp-en{
    font-size: 6px; font-weight: 800; letter-spacing: .1em;
    text-transform: uppercase;
    border-top: 1px solid #111;
    border-bottom: 1px solid #111;
    padding: 0.3mm 1.5mm;
    margin: 0.3mm 0;
  }
  .rpm-lc-stamp-ring .stamp-ar-box{
    border: 1px solid #111;
    border-radius: 1px;
    padding: 0.3mm 1.2mm;
    font-size: 5.6px;
    font-weight: 800;
    margin-top: 0.4mm;
  }
  .rpm-lc-stamp-ring .stamp-cr{ font-size: 5.5px; margin-top: 0.3mm; }
  .rpm-lc-stamp-ring .stamp-stars{ letter-spacing: 0.35mm; font-size: 6.5px; line-height: 1; color: #111; }
  .rpm-lc-stamp-ring img{ width: 8mm; height: auto; object-fit: contain; }
  .foot{ margin-top: 1.5mm; color: #666; font-size: 7.5px; text-align: center; }

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
  return html.includes('rpm-legal-contract')
    || html.includes('rpm-paper-v2')
    || html.includes('rpm-paper-v3')
    || html.includes('شروط التعاقد')
}
