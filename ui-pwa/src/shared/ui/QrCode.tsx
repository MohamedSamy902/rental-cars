import { useMemo } from 'react'
import qrcode from 'qrcode-generator'
import { IconDownload, IconPrinter } from './Icons'
import './QrCode.css'

/** Quiet zone required by the QR spec for reliable scanning */
const QUIET = 4

function utf8Bytes(text: string) {
  return Array.from(new TextEncoder().encode(text))
}

function buildMatrix(value: string) {
  const previous = qrcode.stringToBytes
  qrcode.stringToBytes = utf8Bytes
  try {
    const qr = qrcode(0, 'M')
    qr.addData(value, 'Byte')
    qr.make()
    const count = qr.getModuleCount()
    const dark: [number, number][] = []
    for (let r = 0; r < count; r += 1) {
      for (let c = 0; c < count; c += 1) {
        if (qr.isDark(r, c)) dark.push([c, r])
      }
    }
    return { count, dark }
  } finally {
    qrcode.stringToBytes = previous
  }
}

function modulesPath(dark: [number, number][]) {
  return dark.map(([x, y]) => `M${x + QUIET} ${y + QUIET}h1v1h-1z`).join('')
}

function svgMarkup(dim: number, path: string, px: number) {
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${dim} ${dim}" width="${px}" height="${px}" shape-rendering="crispEdges"><rect width="${dim}" height="${dim}" fill="#ffffff"/><path d="${path}" fill="#000000"/></svg>`
}

type Props = {
  value: string
  /** Shown under the code and used as the download/print title */
  label?: string
  fileName?: string
  size?: number
  actions?: boolean
  className?: string
}

export function QrCode({ value, label, fileName = 'rpm-qr', size = 148, actions = true, className = '' }: Props) {
  const { count, dark } = useMemo(() => buildMatrix(value), [value])
  const dim = count + QUIET * 2
  const path = useMemo(() => modulesPath(dark), [dark])

  function download() {
    const px = 1024
    const img = new Image()
    const blob = new Blob([svgMarkup(dim, path, px)], { type: 'image/svg+xml' })
    const url = URL.createObjectURL(blob)
    img.onload = () => {
      const canvas = document.createElement('canvas')
      canvas.width = px
      canvas.height = px
      const ctx = canvas.getContext('2d')
      if (!ctx) return
      ctx.imageSmoothingEnabled = false
      ctx.drawImage(img, 0, 0, px, px)
      URL.revokeObjectURL(url)
      canvas.toBlob((png) => {
        if (!png) return
        const a = document.createElement('a')
        a.href = URL.createObjectURL(png)
        a.download = `${fileName}.png`
        document.body.appendChild(a)
        a.click()
        a.remove()
        window.setTimeout(() => URL.revokeObjectURL(a.href), 1000)
      }, 'image/png')
    }
    img.src = url
  }

  function print() {
    const frame = document.createElement('iframe')
    frame.setAttribute('aria-hidden', 'true')
    frame.style.position = 'fixed'
    frame.style.inlineSize = '0'
    frame.style.blockSize = '0'
    frame.style.border = '0'
    document.body.appendChild(frame)
    const doc = frame.contentDocument
    if (!doc) {
      frame.remove()
      return
    }
    const title = label ? label.replace(/[<>&]/g, '') : ''
    doc.open()
    doc.write(
      `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>${title}</title>` +
        '<style>@page{margin:16mm}body{margin:0;display:grid;place-items:center;gap:12px;font-family:system-ui,sans-serif;text-align:center}p{margin:0;font-size:14px}</style>' +
        `</head><body>${svgMarkup(dim, path, 320)}${title ? `<p>${title}</p>` : ''}</body></html>`,
    )
    doc.close()
    const run = () => {
      frame.contentWindow?.focus()
      frame.contentWindow?.print()
      window.setTimeout(() => frame.remove(), 1500)
    }
    if (doc.readyState === 'complete') window.setTimeout(run, 50)
    else frame.onload = run
  }

  return (
    <figure className={`rpm-qr ${className}`.trim()}>
      <div className="rpm-qr__plate">
        <svg
          viewBox={`0 0 ${dim} ${dim}`}
          width={size}
          height={size}
          shapeRendering="crispEdges"
          role="img"
          aria-label={label ? `رمز QR: ${label}` : 'رمز QR'}
        >
          <rect width={dim} height={dim} fill="#ffffff" />
          <path d={path} fill="#000000" />
        </svg>
      </div>
      {label ? <figcaption className="rpm-qr__label">{label}</figcaption> : null}
      {actions ? (
        <div className="rpm-qr__actions">
          <button type="button" className="rpm-qr__btn" onClick={download}>
            <IconDownload size={15} />
            <span>تنزيل</span>
          </button>
          <button type="button" className="rpm-qr__btn" onClick={print}>
            <IconPrinter size={15} />
            <span>طباعة</span>
          </button>
        </div>
      ) : null}
    </figure>
  )
}
