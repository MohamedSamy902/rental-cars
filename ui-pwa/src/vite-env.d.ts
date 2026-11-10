/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_ENABLE_DEMO_LOGIN?: string
  readonly VITE_DEMO_LOGIN?: string
  readonly VITE_DEMO_PASSWORD?: string
  /** أصل الباك عند الاستضافة المنفصلة (Vercel → cPanel). فاضي = نفس الدومين. */
  readonly VITE_API_ORIGIN?: string
}
