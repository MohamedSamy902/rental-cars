/** Shared print shell — near-pixel clone of the RPM paper rental contract. */
export function shellRentalContractHtml(body: string, title = 'عقد إيجار سيارة'): string {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${escapeTitle(title)}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@500;600;700&family=Tajawal:wght@700;800&display=swap');
  @page { size: A4; margin: 4.2mm 4mm; }
  * { box-sizing: border-box; }
  html{ margin: 0; }
  body{
    font-family: 'IBM Plex Sans Arabic', 'Tajawal', 'Segoe UI', Tahoma, sans-serif;
    padding: 0; margin: 0;
    color: #111;
    font-size: 11.2px;
    line-height: 1.34;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-optical-sizing: auto;
  }
  .rpm-paper-v2, .rpm-paper-v3, .rpm-paper-v4{
    --rpm-red: #d40000;
    --rpm-red-mid: #e10600;
    --rpm-red-dark: #8a0000;
    --rpm-black: #0a0a0a;
    --rpm-gray: #ececec;
    width: 100%;
    max-width: 202mm;
    margin: 0 auto;
    position: relative;
    padding: 1.6mm 1.8mm 3.6mm;
    border: 1.15px solid #111;
    overflow: visible;
    box-sizing: border-box;
  }
  @media screen{
    html, body{
      margin: 0;
      overflow: visible;
      -webkit-overflow-scrolling: touch;
    }
    body{
      --rpm-preview-scale: 1;
      background: #fff;
    }
    .rpm-preview-root{
      transform: scale(var(--rpm-preview-scale, 1));
      transform-origin: top right;
      width: 100%;
      box-sizing: border-box;
    }
    .rpm-paper-v2, .rpm-paper-v3, .rpm-paper-v4{
      max-width: 202mm;
    }
  }
  .rpm-paper-v2::after, .rpm-paper-v3::after, .rpm-paper-v4::after{
    content: '';
    display: block;
    height: 2px;
    margin-top: 1.6mm;
    background: linear-gradient(90deg, var(--rpm-red-dark), var(--rpm-red-mid), var(--rpm-red-dark));
    border-radius: 2px;
  }

  /* Legacy ribbon — hidden (v4 removes it from HTML) */
  .rpm-lc-ribbon{ display: none !important; }

  .rpm-lc-head{
    display: grid;
    grid-template-columns: 1.08fr 1.55fr 1.08fr;
    gap: 2mm;
    align-items: stretch;
    margin: 0 0 2.4mm;
    background: transparent;
  }
  .rpm-lc-badge{
    background: linear-gradient(180deg, #444 0%, #161616 40%, #000 100%);
    color: #fff;
    border-radius: 999px;
    border: 2.3px solid var(--rpm-red);
    box-shadow:
      inset 0 1.2px 0 rgba(255,255,255,.28),
      inset 0 -1px 0 rgba(0,0,0,.55),
      0 2px 5px rgba(0,0,0,.35);
    padding: 1.8mm 3mm;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    min-height: 14.5mm;
    position: relative;
  }
  .rpm-lc-badge::after{
    content: '';
    position: absolute; inset: 1.2px;
    border-radius: inherit;
    border: 1px solid rgba(255,255,255,.28);
    pointer-events: none;
  }
  .rpm-lc-badge--brand{ padding-block: 1.2mm; gap: 0.3mm; }
  .rpm-lc-badge--title{ padding-inline: 4mm; border-width: 2.5px; }
  .rpm-lc-badge--contact{ align-items: stretch; gap: 1mm; padding-block: 1.3mm; }
  .rpm-lc-brand-mark{
    position: relative;
    font-family: 'Tajawal', sans-serif;
    font-size: 20px; font-weight: 800; letter-spacing: .08em;
    line-height: 1; font-style: italic;
    text-shadow: 0 1px 0 #000;
  }
  .rpm-lc-brand-mark .rpm-lc-swoosh-thru{
    position: absolute;
    left: -4%; right: -4%;
    top: 52%;
    height: 2.2px;
    background: linear-gradient(90deg, transparent, var(--rpm-red-mid) 12%, #fff 48%, var(--rpm-red-mid) 88%, transparent);
    transform: translateY(-50%) skewX(-18deg);
    border-radius: 2px;
  }
  .rpm-lc-tagline{ font-size: 8.4px; font-weight: 700; color: #f5f5f5; }
  .rpm-lc-title-row{
    display: inline-flex; align-items: center; gap: 2mm;
    font-family: 'Tajawal', sans-serif;
    font-size: 15.5px; font-weight: 800; white-space: nowrap;
  }
  .rpm-lc-ico-handshake{ width: 7.2mm; height: 7.2mm; flex: 0 0 auto; filter: drop-shadow(0 1px 0 rgba(0,0,0,.35)); }
  .rpm-lc-signer-short{ font-size: 11.5px; font-weight: 800; text-align: center; }
  .rpm-lc-phone-pill{
    display: flex; align-items: center; justify-content: center; gap: 1.4mm;
    background: linear-gradient(180deg, #ff1a1a 0%, #b00000 100%);
    border-radius: 999px;
    padding: .85mm 2.2mm;
    font-size: 10px; font-weight: 800;
    border: 1px solid rgba(255,255,255,.4);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.25);
    direction: ltr;
  }
  .rpm-lc-phone-dot{
    width: 4.2mm; height: 4.2mm; border-radius: 50%;
    background: #fff;
    display: grid; place-items: center;
    flex: 0 0 auto;
  }
  .rpm-lc-ico-phone{ width: 2.6mm; height: 2.6mm; }

  .rpm-lc-meta{ display: none; }

  .rpm-lc-block{
    position: relative;
    border: 1.25px solid #000;
    border-radius: 2.8mm;
    padding: 2.2mm 3mm 4.4mm;
    margin: 0 0 2.6mm;
    background: #fff;
  }
  .rpm-lc-block p{
    margin: .9mm 0;
    font-weight: 600;
    font-size: 11px;
    line-height: 1.36;
  }
  .rpm-lc-row{ text-align: justify; }
  .rpm-lc-block--icon{ padding-inline-start: 15.5mm; }
  .rpm-lc-float-ico{
    position: absolute;
    inset-inline-start: 1.8mm;
    top: 50%;
    transform: translateY(-50%);
    width: 11.2mm; height: 11.2mm;
    border-radius: 50%;
    display: grid; place-items: center;
  }
  .rpm-lc-float-ico--car{
    background: #111;
    border: 2px solid var(--rpm-red);
    box-shadow: inset 0 0 0 1.1px #fff, 0 1px 3px rgba(0,0,0,.3);
  }
  .rpm-lc-float-ico--cal{
    background: linear-gradient(180deg, #ff1a1a, #8a0000);
    border: 1.3px solid #5c0000;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.3), 0 1px 3px rgba(0,0,0,.28);
  }
  .rpm-lc-float-ico svg{ width: 6.5mm; height: 6.5mm; }

  .rpm-lc-party-tag{
    position: absolute;
    left: 3mm;
    bottom: -2.6mm;
    background: linear-gradient(180deg, #ff1a1a 0%, #9b0000 100%);
    color: #fff;
    border-radius: 999px;
    padding: .95mm 3.2mm;
    font-size: 9px;
    font-weight: 800;
    box-shadow: 0 1px 3px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.25);
    white-space: nowrap;
    z-index: 2;
    border: 1px solid #6b0000;
  }

  .fill{
    display: inline-block;
    min-width: 4.2em;
    min-height: 1em;
    padding: 0 1.1mm;
    border-bottom: 1px dotted #222;
    font-weight: 700;
  }
  .fill.wide{ min-width: 11em; }
  .fill.mid{ min-width: 6.5em; }

  .rpm-lc-delivery{
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1.6mm 3mm;
    margin-top: 1.2mm !important;
  }
  .rpm-lc-check{
    display: inline-flex;
    align-items: center;
    gap: .8mm;
    font-weight: 800;
    font-size: 10.4px;
    white-space: nowrap;
  }

  .rpm-lc-terms-wrap{ margin: 2.4mm 0 1mm; }
  .rpm-lc-terms-banner{
    display: flex; align-items: center; gap: 0;
    margin: 0 0 1.8mm;
    background: transparent;
    padding: 0;
    text-align: center;
  }
  .rpm-lc-terms-banner::before,
  .rpm-lc-terms-banner::after{
    content: '';
    flex: 1 1 auto;
    height: 1.5px;
    background: linear-gradient(90deg, transparent, var(--rpm-red-mid) 18%, var(--rpm-red-mid) 82%, transparent);
    position: relative;
  }
  .rpm-lc-terms-banner::before{
    clip-path: polygon(0 45%, 88% 0, 100% 50%, 88% 100%, 0 55%);
    height: 4.2mm;
    background: var(--rpm-red-mid);
  }
  .rpm-lc-terms-banner::after{
    clip-path: polygon(12% 0, 100% 45%, 100% 55%, 12% 100%, 0 50%);
    height: 4.2mm;
    background: var(--rpm-red-mid);
  }
  .rpm-lc-terms-banner > span{
    flex: 0 0 auto;
    background: linear-gradient(180deg, #ff1a1a, #9b0000);
    color: #fff;
    font-family: 'Tajawal', sans-serif;
    font-size: 12.5px;
    font-weight: 800;
    padding: 1.2mm 8mm;
    border-radius: 999px;
    border: 1px solid #5c0000;
    box-shadow: 0 1px 3px rgba(0,0,0,.3), inset 0 1px 0 rgba(255,255,255,.25);
    margin-inline: 1mm;
    letter-spacing: .02em;
  }
  .rpm-lc-terms{
    margin: 0; padding: 0; list-style: none;
    display: flex; flex-direction: column; gap: .75mm;
  }
  .rpm-lc-terms li{
    margin: 0;
    padding: 1.25mm 2.1mm 1.25mm 2.2mm;
    text-align: justify;
    font-size: 10.3px;
    font-weight: 600;
    line-height: 1.35;
    background: var(--rpm-gray);
    border-radius: 2mm;
  }
  .rpm-lc-band{
    display: inline-block;
    background: linear-gradient(180deg, #ff1a1a, #8a0000);
    color: #fff;
    border-radius: 999px;
    padding: .5mm 2.7mm;
    margin-inline-end: 1.3mm;
    font-size: 8.8px;
    font-weight: 800;
    box-shadow: 0 1px 2px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.22);
    vertical-align: baseline;
    border: 1px solid #6b0000;
  }
  .rpm-lc-extra-terms .terms:empty,
  .rpm-lc-extra-terms:has(.terms:empty){ display: none; }
  .rpm-lc-extra-terms .terms{
    white-space: pre-wrap;
    margin-top: 1.4mm;
    font-size: 9px;
    border-top: 1px dashed #999;
    padding-top: 1.4mm;
  }

  .rpm-lc-sign{
    display: grid;
    grid-template-columns: 1fr 30mm 1fr;
    gap: 2mm;
    align-items: start;
    margin-top: 4.2mm;
    margin-inline: 0.3mm;
    width: 100%;
    box-sizing: border-box;
  }
  .rpm-lc-sign-col{
    position: relative;
    border: 1.25px solid #000;
    border-radius: 2.6mm;
    padding: 4.4mm 2.6mm 2.2mm;
    min-height: 23mm;
  }
  .rpm-lc-sign-col .rpm-lc-party-tag{
    position: absolute;
    left: 50%;
    bottom: auto;
    top: -2.6mm;
    transform: translateX(-50%);
  }
  .rpm-lc-sign-col p{ margin: 1mm 0; font-weight: 700; font-size: 10.2px; }
  .rpm-lc-sign-line{ margin-top: 3.2mm !important; }

  .rpm-lc-stamp{
    display: flex; align-items: center; justify-content: center;
    padding-top: 0.3mm;
  }
  .rpm-lc-stamp-ring{
    width: 29mm; height: 29mm;
    border-radius: 50%;
    border: 2.2px double #111;
    box-shadow: inset 0 0 0 1.2px #111;
    color: #111;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; gap: 0.2mm; padding: 1.3mm;
    font-size: 5.8px; font-weight: 800;
    background: #fff;
    position: relative;
  }
  .rpm-lc-stamp-car{ width: 11mm; height: 5.4mm; margin-bottom: 0.3mm; }
  .rpm-lc-stamp-ring .stamp-rpm{
    font-family: 'Tajawal', sans-serif;
    font-size: 11px; font-weight: 800; letter-spacing: .04em; line-height: 1;
  }
  .rpm-lc-stamp-ring .stamp-en{
    font-size: 5.6px; font-weight: 800; letter-spacing: .1em;
    text-transform: uppercase;
    border-top: 1px solid #111;
    border-bottom: 1px solid #111;
    padding: 0.25mm 1.3mm;
    margin: 0.25mm 0;
  }
  .rpm-lc-stamp-ring .stamp-ar-box{
    border: 1px solid #111;
    border-radius: 1px;
    padding: 0.25mm 1mm;
    font-size: 5.3px;
    font-weight: 800;
    margin-top: 0.3mm;
  }
  .rpm-lc-stamp-ring .stamp-cr{ font-size: 5.2px; margin-top: 0.25mm; }
  .rpm-lc-stamp-ring .stamp-stars{ letter-spacing: 0.3mm; font-size: 6px; line-height: 1; color: #111; }
  .foot{ margin-top: 1.2mm; color: #666; font-size: 7.2px; text-align: center; }

  @media print{
    html, body{
      width: 100%;
      overflow: visible !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .rpm-preview-root{
      transform: none !important;
      width: 100% !important;
    }
    .rpm-paper-v2, .rpm-paper-v3, .rpm-paper-v4{
      width: 100%;
      max-width: 100%;
      margin: 0;
      padding: .6mm .8mm 1.8mm;
      border-width: 1px;
    }
    body{ font-size: 11px; }
    .rpm-lc-terms li{ font-size: 10.2px; line-height: 1.33; }
  }
</style></head><body><div class="rpm-preview-root">${body}</div></body></html>`
}

function escapeTitle(title: string) {
  return title.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] || ch))
}

/** True when the rendered body looks like the paper-form legal contract. */
export function isRentalContractBody(html: string) {
  return html.includes('rpm-legal-contract')
    || html.includes('rpm-paper-v4')
    || html.includes('rpm-paper-v3')
    || html.includes('rpm-paper-v2')
    || html.includes('شروط التعاقد')
}
