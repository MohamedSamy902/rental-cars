import './BrandLogo.css'

type Props = {
  /** full = RPM + Rent A Car lockup · mark = RPM wordmark for tight spaces */
  variant?: 'full' | 'mark'
  /** Drop the black tile when the host surface is already black */
  bare?: boolean
  className?: string
}

const assets = {
  full: { src: 'brand/rpm-logo.webp', width: 640, height: 305 },
  mark: { src: 'brand/rpm-mark.webp', width: 320, height: 120 },
}

export function BrandLogo({ variant = 'full', bare = false, className = '' }: Props) {
  const asset = assets[variant]
  return (
    <span className={`rpm-logo rpm-logo--${variant} ${bare ? 'rpm-logo--bare' : ''} ${className}`.replace(/\s+/g, ' ').trim()}>
      <img
        src={`${import.meta.env.BASE_URL}${asset.src}`}
        alt="RPM Rent A Car"
        width={asset.width}
        height={asset.height}
        decoding="async"
        draggable={false}
      />
    </span>
  )
}
