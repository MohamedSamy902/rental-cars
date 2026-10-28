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
export const IconChevron = (p: Props) => (
  <Svg {...p}><path d="m9 18 6-6-6-6" /></Svg>
)
export const IconMenu = (p: Props) => (
  <Svg {...p}><path d="M4 7h16M4 12h16M4 17h10" /></Svg>
)
export const IconSpark = (p: Props) => (
  <Svg {...p}><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" /></Svg>
)
export const IconMore = (p: Props) => (
  <Svg {...p}><circle cx="5" cy="12" r="1.5" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" /><circle cx="19" cy="12" r="1.5" fill="currentColor" stroke="none" /></Svg>
)
export const IconReturn = (p: Props) => (
  <Svg {...p}><path d="M9 14 4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" /></Svg>
)
export const IconTrend = (p: Props) => (
  <Svg {...p}><path d="M3 17 10 10l4 4 7-7" /><path d="M14 7h6v6" /></Svg>
)
export const IconSun = (p: Props) => (
  <Svg {...p}><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></Svg>
)
export const IconMoon = (p: Props) => (
  <Svg {...p}><path d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5z" /></Svg>
)
export const IconShield = (p: Props) => (
  <Svg {...p}><path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6z" /><path d="m9 12 2 2 4-4" /></Svg>
)
export const IconBadge = (p: Props) => (
  <Svg {...p}><circle cx="12" cy="8" r="4" /><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2" /></Svg>
)
export const IconClose = (p: Props) => (
  <Svg {...p}><path d="M6 6l12 12M18 6 6 18" /></Svg>
)
export const IconCheck = (p: Props) => (
  <Svg {...p}><path d="m5 12.5 4.5 4.5L19 7.5" /></Svg>
)
export const IconSortNone = (p: Props) => (
  <Svg {...p}><path d="m8 9 4-4 4 4M8 15l4 4 4-4" /></Svg>
)
export const IconSortAsc = (p: Props) => (
  <Svg {...p}><path d="M12 19V5M6 11l6-6 6 6" /></Svg>
)
export const IconSortDesc = (p: Props) => (
  <Svg {...p}><path d="M12 5v14M6 13l6 6 6-6" /></Svg>
)
export const IconEye = (p: Props) => (
  <Svg {...p}><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></Svg>
)
export const IconEdit = (p: Props) => (
  <Svg {...p}><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z" /><path d="m13.5 6.5 4 4" /></Svg>
)
export const IconPrinter = (p: Props) => (
  <Svg {...p}><path d="M7 9V3h10v6" /><rect x="3" y="9" width="18" height="8" rx="2" /><path d="M7 14h10v7H7z" /></Svg>
)
export const IconBan = (p: Props) => (
  <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="m5.6 5.6 12.8 12.8" /></Svg>
)
export const IconCash = (p: Props) => (
  <Svg {...p}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 12h.01M18 12h.01" /></Svg>
)
export const IconKey = (p: Props) => (
  <Svg {...p}><circle cx="8" cy="15" r="4" /><path d="m10.8 12.2 9.2-9.2M17 6l3 3M15 8l2 2" /></Svg>
)
export const IconStore = (p: Props) => (
  <Svg {...p}><path d="M3 9 4.5 4h15L21 9" /><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" /><path d="M5 11.5V21h14v-9.5" /><path d="M10 21v-5h4v5" /></Svg>
)
export const IconReceipt = (p: Props) => (
  <Svg {...p}><path d="M5 3h14v18l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21z" /><path d="M9 8h6M9 12h6M9 16h3" /></Svg>
)
export const IconLandmark = (p: Props) => (
  <Svg {...p}><path d="M3 21h18M4 18h16M12 3 3 8h18z" /><path d="M6 11v7M10 11v7M14 11v7M18 11v7" /></Svg>
)
export const IconBriefcase = (p: Props) => (
  <Svg {...p}><rect x="3" y="7" width="18" height="13" rx="2" /><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18" /></Svg>
)
export const IconFileText = (p: Props) => (
  <Svg {...p}><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5M9 13h6M9 17h6M9 9h2" /></Svg>
)
export const IconChat = (p: Props) => (
  <Svg {...p}><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.6A8 8 0 1 1 21 12z" /><path d="M8.5 12h.01M12 12h.01M15.5 12h.01" /></Svg>
)
export const IconUserCog = (p: Props) => (
  <Svg {...p}><circle cx="9" cy="8" r="4" /><path d="M2 21v-1a6 6 0 0 1 9.5-4.9" /><circle cx="18" cy="17" r="2.5" /><path d="M18 13v1.5M18 19.5V21M14.5 17H16M20 17h1.5M15.5 14.5l1 1M19.5 18.5l1 1M20.5 14.5l-1 1M16.5 18.5l-1 1" /></Svg>
)
export const IconHistory = (p: Props) => (
  <Svg {...p}><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5M12 7v5l3.5 2" /></Svg>
)
export const IconMail = (p: Props) => (
  <Svg {...p}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></Svg>
)
export const IconLock = (p: Props) => (
  <Svg {...p}><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></Svg>
)
export const IconEyeOff = (p: Props) => (
  <Svg {...p}><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.1 4.1M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6" /><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18" /></Svg>
)
export const IconDownload = (p: Props) => (
  <Svg {...p}><path d="M12 3v12M7 10l5 5 5-5M4 21h16" /></Svg>
)
export const IconZap = (p: Props) => (
  <Svg {...p}><path d="M13 2 4 14h7l-1 8 9-12h-7z" /></Svg>
)
export const IconPhone = (p: Props) => (
  <Svg {...p}><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z" /></Svg>
)
