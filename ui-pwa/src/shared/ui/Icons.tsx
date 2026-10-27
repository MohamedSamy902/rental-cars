type Props = { size?: number; className?: string }

function Svg({ size = 18, className, children }: Props & { children: React.ReactNode }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden
    >
      {children}
    </svg>
  )
}

export const IconHome = (p: Props) => (
  <Svg {...p}><path d="M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" /></Svg>
)
export const IconCar = (p: Props) => (
  <Svg {...p}><path d="M3 13h18l-1.5-4.5A3 3 0 0 0 16.6 7H7.4a3 3 0 0 0-2.9 1.5L3 13z" /><path d="M5 17h.01M19 17h.01" /><circle cx="7" cy="17" r="2" /><circle cx="17" cy="17" r="2" /><path d="M3 13v2a2 2 0 0 0 2 2h1M18 17h1a2 2 0 0 0 2-2v-2" /></Svg>
)
export const IconCalendar = (p: Props) => (
  <Svg {...p}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></Svg>
)
export const IconClipboard = (p: Props) => (
  <Svg {...p}><rect x="6" y="4" width="12" height="18" rx="2" /><path d="M9 4V3h6v1M9 12h6M9 16h4" /></Svg>
)
export const IconUsers = (p: Props) => (
  <Svg {...p}><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="3" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a3 3 0 0 1 0 5.74" /></Svg>
)
export const IconWrench = (p: Props) => (
  <Svg {...p}><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-2.5z" /></Svg>
)
export const IconBox = (p: Props) => (
  <Svg {...p}><path d="m12 3 9 5v8l-9 5-9-5V8z" /><path d="M12 13V3M3 8l9 5 9-5" /></Svg>
)
export const IconWallet = (p: Props) => (
  <Svg {...p}><path d="M3 7a2 2 0 0 1 2-2h14v14H5a2 2 0 0 1-2-2z" /><path d="M21 10h-5a2 2 0 0 0 0 4h5" /></Svg>
)
export const IconBell = (p: Props) => (
  <Svg {...p}><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8" /><path d="M10 20a2 2 0 0 0 4 0" /></Svg>
)
export const IconSettings = (p: Props) => (
  <Svg {...p}><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></Svg>
)
export const IconAlert = (p: Props) => (
  <Svg {...p}><path d="M12 9v4M12 17h.01" /><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></Svg>
)
export const IconChart = (p: Props) => (
  <Svg {...p}><path d="M4 19V5M4 19h16" /><path d="M8 17V10M12 17V7M16 17v-5" /></Svg>
)
export const IconSearch = (p: Props) => (
  <Svg {...p}><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" /></Svg>
)
export const IconPlus = (p: Props) => (
  <Svg {...p}><path d="M12 5v14M5 12h14" /></Svg>
)
export const IconLogout = (p: Props) => (
  <Svg {...p}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5M21 12H9" /></Svg>
)
export const IconQueue = (p: Props) => (
  <Svg {...p}><path d="M4 6h16M4 12h10M4 18h7" /><circle cx="18" cy="15" r="3" /></Svg>
)
