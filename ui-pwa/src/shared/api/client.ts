const TOKEN_KEY = 'rpm_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null) {
  if (!token) localStorage.removeItem(TOKEN_KEY)
  else localStorage.setItem(TOKEN_KEY, token)
}

export type ApiError = {
  message: string
  errors?: Record<string, string[]>
  status: number
}

type ApiOptions = RequestInit & {
  json?: unknown
  /** إعادة استخدام نفس مفتاح التكرار (مثلاً عند إعادة المحاولة اليدوية). */
  idempotencyKey?: string
}

const inflightMutations = new Map<string, Promise<unknown>>()

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `idem-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

function isMutatingMethod(method: string): boolean {
  return !['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())
}

function applyIdempotency(headers: Headers, method: string, explicit?: string): string | null {
  if (!isMutatingMethod(method)) return null
  const existing = headers.get('Idempotency-Key') || explicit || newIdempotencyKey()
  headers.set('Idempotency-Key', existing)
  return existing
}

export async function api<T>(
  path: string,
  options: ApiOptions = {},
): Promise<T> {
  const headers = new Headers(options.headers || {})
  headers.set('Accept', 'application/json')
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json')
  }
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const method = (options.method || (options.json !== undefined ? 'POST' : 'GET')).toUpperCase()
  const idemKey = applyIdempotency(headers, method, options.idempotencyKey)

  if (idemKey && inflightMutations.has(idemKey)) {
    return inflightMutations.get(idemKey) as Promise<T>
  }

  const run = (async () => {
    const res = await fetch(`/api${path}`, {
      ...options,
      method,
      headers,
      body: options.json !== undefined ? JSON.stringify(options.json) : options.body,
    })

    const text = await res.text()
    const data = text ? JSON.parse(text) : null

    if (!res.ok) {
      const err: ApiError = {
        message: data?.message || 'تعذّر تنفيذ الطلب.',
        errors: data?.errors,
        status: res.status,
      }
      throw err
    }

    return data as T
  })()

  if (idemKey) {
    inflightMutations.set(idemKey, run)
    void run.finally(() => {
      inflightMutations.delete(idemKey)
    })
  }

  return run
}

/** رفع ملف multipart — لا تُضبط Content-Type يدوياً (الحد الفاصل يضعه المتصفح). */
export async function apiUpload<T>(
  path: string,
  formData: FormData,
  options: { idempotencyKey?: string } = {},
): Promise<T> {
  const headers = new Headers({ Accept: 'application/json' })
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const idemKey = applyIdempotency(headers, 'POST', options.idempotencyKey)

  if (idemKey && inflightMutations.has(idemKey)) {
    return inflightMutations.get(idemKey) as Promise<T>
  }

  const run = (async () => {
    const res = await fetch(`/api${path}`, {
      method: 'POST',
      headers,
      body: formData,
    })

    const text = await res.text()
    const data = text ? JSON.parse(text) : null

    if (!res.ok) {
      const err: ApiError = {
        message: data?.message || 'تعذّر رفع الملف.',
        errors: data?.errors,
        status: res.status,
      }
      throw err
    }

    return data as T
  })()

  if (idemKey) {
    inflightMutations.set(idemKey, run)
    void run.finally(() => {
      inflightMutations.delete(idemKey)
    })
  }

  return run
}

/** جلب ملف وسائط خاص كمحتوى ثنائي (للمعاينة بعد المصادقة). */
export async function apiBlob(path: string): Promise<Blob> {
  const headers = new Headers({ Accept: '*/*' })
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const normalized = path.startsWith('/api/')
    ? path.slice(4)
    : path.startsWith('http')
      ? new URL(path).pathname.replace(/^\/api/, '')
      : path.startsWith('/')
        ? path
        : `/${path}`

  const res = await fetch(`/api${normalized}`, { headers })
  if (!res.ok) {
    const err: ApiError = {
      message: 'تعذّر تحميل الملف.',
      status: res.status,
    }
    throw err
  }
  return res.blob()
}
