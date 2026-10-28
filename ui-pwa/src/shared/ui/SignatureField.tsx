import { useEffect, useRef, useState } from 'react'
import { FieldShell } from './Field'
import './SignatureField.css'

type Props = {
  label?: string
  required?: boolean
  error?: string
  hint?: string
  value?: string
  onChange: (dataUrl: string) => void
}

export function SignatureField({
  label = 'توقيع العميل',
  required,
  error,
  hint = 'وقّع بالإصبع أو القلم على الشاشة',
  value,
  onChange,
}: Props) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const [hasInk, setHasInk] = useState(Boolean(value))

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const resize = () => {
      const parent = canvas.parentElement
      if (!parent) return
      const ratio = window.devicePixelRatio || 1
      const w = parent.clientWidth
      const h = 160
      canvas.width = Math.floor(w * ratio)
      canvas.height = Math.floor(h * ratio)
      canvas.style.width = `${w}px`
      canvas.style.height = `${h}px`
      const ctx = canvas.getContext('2d')
      if (!ctx) return
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0)
      ctx.lineWidth = 2.2
      ctx.lineCap = 'round'
      ctx.lineJoin = 'round'
      ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#0f172a'
      if (value) {
        const img = new Image()
        img.onload = () => ctx.drawImage(img, 0, 0, w, h)
        img.src = value
      }
    }
    resize()
    window.addEventListener('resize', resize)
    return () => window.removeEventListener('resize', resize)
  }, [])

  function pos(e: React.PointerEvent<HTMLCanvasElement>) {
    const rect = e.currentTarget.getBoundingClientRect()
    return { x: e.clientX - rect.left, y: e.clientY - rect.top }
  }

  function start(e: React.PointerEvent<HTMLCanvasElement>) {
    const ctx = canvasRef.current?.getContext('2d')
    if (!ctx) return
    drawing.current = true
    e.currentTarget.setPointerCapture(e.pointerId)
    const p = pos(e)
    ctx.beginPath()
    ctx.moveTo(p.x, p.y)
  }

  function move(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!drawing.current) return
    const ctx = canvasRef.current?.getContext('2d')
    if (!ctx) return
    const p = pos(e)
    ctx.lineTo(p.x, p.y)
    ctx.stroke()
    setHasInk(true)
  }

  function end() {
    if (!drawing.current) return
    drawing.current = false
    const canvas = canvasRef.current
    if (!canvas) return
    setHasInk(true)
    onChange(canvas.toDataURL('image/png'))
  }

  function clear() {
    const canvas = canvasRef.current
    const ctx = canvas?.getContext('2d')
    if (!canvas || !ctx) return
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    setHasInk(false)
    onChange('')
  }

  return (
    <FieldShell label={label} required={required} error={error} hint={hint}>
      <div className="rpm-sign">
        <canvas
          ref={canvasRef}
          className="rpm-sign__canvas"
          onPointerDown={start}
          onPointerMove={move}
          onPointerUp={end}
          onPointerCancel={end}
        />
        <div className="rpm-sign__bar">
          <span className="muted">{hasInk ? 'تم التوقيع' : 'بانتظار التوقيع'}</span>
          <button type="button" className="rpm-sign__clear" onClick={clear}>مسح</button>
        </div>
      </div>
    </FieldShell>
  )
}
