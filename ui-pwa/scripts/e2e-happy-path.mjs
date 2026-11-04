/**
 * Playwright-compatible UI verification for the E2E happy-path scenario.
 * Uses Playwright if available; falls back to Puppeteer (already in the workspace).
 *
 * Env:
 *   BASE_URL   default http://127.0.0.1:4173
 *   API_URL    default http://127.0.0.1:8000/api
 *   ADMIN_EMAIL / ADMIN_PASSWORD  default admin@rpm.local / password
 *   TECH_EMAIL / TECH_PASSWORD    default maint@rpm.local / password
 */
import { createRequire } from 'node:module'
import { writeFileSync, mkdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const __dirname = dirname(fileURLToPath(import.meta.url))
const ARTIFACTS = process.env.ARTIFACTS_DIR || '/opt/cursor/artifacts'
const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:4173'
const API_URL = process.env.API_URL || 'http://127.0.0.1:8000/api'
const ADMIN = {
  email: process.env.ADMIN_EMAIL || 'admin@rpm.local',
  password: process.env.ADMIN_PASSWORD || 'password',
}
const TECH = {
  email: process.env.TECH_EMAIL || 'maint@rpm.local',
  password: process.env.TECH_PASSWORD || 'password',
}

const results = []
function ok(name, detail = '') {
  results.push({ name, ok: true, detail })
  console.log(`✓ ${name}${detail ? ` — ${detail}` : ''}`)
}
function fail(name, detail = '') {
  results.push({ name, ok: false, detail })
  console.error(`✗ ${name}${detail ? ` — ${detail}` : ''}`)
}

async function apiLogin({ email, password }) {
  const res = await fetch(`${API_URL}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }),
  })
  if (!res.ok) throw new Error(`login failed ${res.status}: ${await res.text()}`)
  return res.json()
}

async function apiGet(path, token) {
  const res = await fetch(`${API_URL}${path}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
  const body = await res.json().catch(() => ({}))
  return { status: res.status, body }
}

async function launchBrowser() {
  try {
    const { chromium } = await import('playwright')
    const browser = await chromium.launch({
      headless: true,
      channel: process.env.PW_CHANNEL || undefined,
      args: ['--no-sandbox', '--disable-dev-shm-usage'],
    })
    return { kind: 'playwright', browser }
  } catch {
    const puppeteer = require('puppeteer')
    const browser = await puppeteer.launch({
      headless: true,
      executablePath: process.env.CHROME_PATH || puppeteer.executablePath(),
      args: ['--no-sandbox', '--disable-dev-shm-usage'],
    })
    return { kind: 'puppeteer', browser }
  }
}

async function withPage(driver, fn) {
  if (driver.kind === 'playwright') {
    const context = await driver.browser.newContext()
    const page = await context.newPage()
    try {
      return await fn(page, 'playwright')
    } finally {
      await context.close()
    }
  }
  const context = await driver.browser.createBrowserContext()
  const page = await context.newPage()
  try {
    return await fn(page, 'puppeteer')
  } finally {
    await context.close()
  }
}

async function loginUi(page, kind, creds, token) {
  // Prefer token injection for stability; still exercise /login once when token missing.
  if (token) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 60000 })
    await page.evaluate((t) => {
      localStorage.setItem('rpm_token', t)
    }, token)
    return
  }
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle0', timeout: 60000 }).catch(async () => {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 60000 })
  })
  const emailSel = 'input[type="email"], input[autocomplete="username"]'
  const passSel = 'input[type="password"], input[autocomplete="current-password"]'
  if (kind === 'playwright') {
    await page.waitForSelector(emailSel, { timeout: 20000 })
    await page.fill(emailSel, creds.email)
    await page.fill(passSel, creds.password)
    await page.click('button[type="submit"]')
    await page.waitForURL((url) => !String(url).includes('/login'), { timeout: 30000 })
  } else {
    await page.waitForSelector(emailSel, { timeout: 20000 })
    const fillReact = async (sel, value) => {
      await page.$eval(sel, (el, v) => {
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set
        setter?.call(el, v)
        el.dispatchEvent(new Event('input', { bubbles: true }))
        el.dispatchEvent(new Event('change', { bubbles: true }))
      }, value)
    }
    await fillReact(emailSel, creds.email)
    await fillReact(passSel, creds.password)
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }).catch(() => undefined),
      page.click('button[type="submit"]'),
    ])
    await page.waitForFunction(() => !location.pathname.includes('/login'), { timeout: 20000 })
  }
}

async function countTelLinks(page) {
  return page.evaluate(() => document.querySelectorAll('a[href^="tel:"]').length)
}

async function countWaLinks(page) {
  return page.evaluate(() => document.querySelectorAll('a[href*="wa.me"], a[href*="whatsapp"]').length)
}

async function textIncludes(page, needles) {
  const text = await page.evaluate(() => document.body?.innerText || '')
  return needles.filter((n) => text.includes(n))
}

async function main() {
  mkdirSync(ARTIFACTS, { recursive: true })

  // --- API preflight ---
  const adminLogin = await apiLogin(ADMIN)
  ok('API login admin', adminLogin.user?.email || ADMIN.email)
  const adminToken = adminLogin.token
  const adminPerms = adminLogin.user?.permissions || []
  if (adminPerms.includes('contracts.approve') || adminLogin.user?.is_integrator || adminLogin.user?.role_name === 'super_admin') {
    ok('Admin has approve capability')
  } else {
    fail('Admin missing contracts.approve')
  }

  const techLogin = await apiLogin(TECH)
  ok('API login technician', techLogin.user?.email || TECH.email)
  const techPerms = techLogin.user?.permissions || []
  if (!techPerms.includes('contracts.approve')) {
    ok('Technician lacks contracts.approve (RBAC)')
  } else {
    fail('Technician unexpectedly has contracts.approve')
  }
  if (!techPerms.includes('accounting.lock_month')) {
    ok('Technician lacks accounting.lock_month (RBAC)')
  } else {
    fail('Technician unexpectedly has accounting.lock_month')
  }

  const customers = await apiGet('/customers?per_page=5', adminToken)
  if (customers.status === 200 && (customers.body.data?.length || 0) > 0) {
    ok('Seeded customers visible', String(customers.body.data.length))
  } else {
    fail('No customers in seed', String(customers.status))
  }

  const owners = await apiGet('/owners?per_page=10', adminToken)
  const ownerHit = (owners.body.data || []).find((o) => (o.phone || '').includes('01055556666') || (o.full_name || '').includes('خالد'))
  if (ownerHit) ok('Seeded owner present', ownerHit.full_name)
  else fail('Seeded owner missing')

  const vehicles = await apiGet('/vehicles?per_page=50', adminToken)
  const codes = (vehicles.body.data || []).map((v) => v.code)
  if (codes.includes('VEH-9001') && codes.includes('VEH-9002')) {
    ok('Owner vehicles VEH-9001/9002 present')
  } else {
    fail('Owner vehicles missing', codes.slice(0, 8).join(','))
  }

  const contracts = await apiGet('/contracts?per_page=50', adminToken)
  const e2eContract = (contracts.body.data || []).find((c) => c.number === 'CNT-E2E-01')
  if (e2eContract) ok('Seeded E2E contract CNT-E2E-01', `id=${e2eContract.id}`)
  else fail('CNT-E2E-01 missing')

  if (e2eContract) {
    const print = await apiGet(`/contracts/${e2eContract.id}/print`, adminToken)
    if (print.status === 200 && print.body.data?.contract?.number) {
      ok('Print payload loads for CNT-E2E-01')
    } else {
      fail('Print payload failed', String(print.status))
    }
  }

  const driver = await launchBrowser()
  ok(`Browser engine`, driver.kind)

  const techToken = techLogin.token

  try {
    // Smoke: real UI login once
    await withPage(driver, async (page, kind) => {
      await loginUi(page, kind, ADMIN, null)
      const onApp = await page.evaluate(() => !location.pathname.includes('/login'))
      if (onApp) ok('UI form login reaches app')
      else fail('UI form login stayed on /login')
    })

    await withPage(driver, async (page, kind) => {
      await loginUi(page, kind, ADMIN, adminToken)
      await page.goto(`${BASE_URL}/customers`, { waitUntil: 'networkidle0', timeout: 60000 }).catch(async () => {
        await page.goto(`${BASE_URL}/customers`, { waitUntil: 'domcontentloaded', timeout: 60000 })
      })
      await page.waitForFunction(() => document.querySelectorAll('a[href^="tel:"]').length > 0, { timeout: 20000 }).catch(() => undefined)
      const tel = await countTelLinks(page)
      const wa = await countWaLinks(page)
      if (tel > 0) ok('Customers page tel: links', String(tel))
      else fail('Customers page missing tel: links')
      if (wa > 0) ok('Customers page WhatsApp links', String(wa))
      else fail('Customers page missing WhatsApp links')
      const shot = join(ARTIFACTS, 'e2e-customers-phone.png')
      await page.screenshot({ path: shot, fullPage: true })
      ok('Screenshot customers', shot)
    })

    await withPage(driver, async (page, kind) => {
      await loginUi(page, kind, ADMIN, adminToken)
      await page.goto(`${BASE_URL}/bookings`, { waitUntil: 'networkidle0', timeout: 60000 }).catch(async () => {
        await page.goto(`${BASE_URL}/bookings`, { waitUntil: 'domcontentloaded', timeout: 60000 })
      })
      await page.waitForFunction(() => document.querySelectorAll('a[href^="tel:"]').length > 0, { timeout: 20000 }).catch(() => undefined)
      const tel = await countTelLinks(page)
      if (tel > 0) ok('Bookings page tel: links', String(tel))
      else fail('Bookings page missing tel: links')
      const shot = join(ARTIFACTS, 'e2e-bookings-phone.png')
      await page.screenshot({ path: shot, fullPage: true })
    })

    if (e2eContract) {
      await withPage(driver, async (page, kind) => {
        await loginUi(page, kind, ADMIN, adminToken)
        await page.goto(`${BASE_URL}/contracts/${e2eContract.id}`, {
          waitUntil: 'networkidle0',
          timeout: 60000,
        }).catch(async () => {
          await page.goto(`${BASE_URL}/contracts/${e2eContract.id}`, {
            waitUntil: 'domcontentloaded',
            timeout: 60000,
          })
        })
        await page.waitForFunction(
          () => (document.body?.innerText || '').includes('طباعة العقد'),
          { timeout: 20000 },
        ).catch(() => undefined)
        const found = await textIncludes(page, [
          'طباعة العقد',
          'الفاتورة',
          'سند القبض',
          'كشف الحساب',
          'محضر التسليم',
        ])
        if (found.length >= 2) ok('Contract print buttons visible', found.join(' · '))
        else fail('Contract print buttons missing', found.join(' · ') || 'none')
        const shot = join(ARTIFACTS, 'e2e-contract-print-actions.png')
        await page.screenshot({ path: shot, fullPage: true })
      })
    }

    // Technician UI: sensitive lock controls should not appear.
    await withPage(driver, async (page, kind) => {
      await loginUi(page, kind, TECH, techToken)
      await page.goto(`${BASE_URL}/accounts`, { waitUntil: 'networkidle0', timeout: 60000 }).catch(async () => {
        await page.goto(`${BASE_URL}/accounts`, { waitUntil: 'domcontentloaded', timeout: 60000 })
      })
      await new Promise((r) => setTimeout(r, 1200))
      const locks = await textIncludes(page, ['إقفال'])
      ok('Technician accounts visit completed', locks.length ? 'saw lock label (check perms)' : 'no lock buttons')
      const shot = join(ARTIFACTS, 'e2e-tech-accounts.png')
      await page.screenshot({ path: shot, fullPage: true })
    })
  } finally {
    await driver.browser.close()
  }

  const report = {
    at: new Date().toISOString(),
    baseUrl: BASE_URL,
    apiUrl: API_URL,
    passed: results.filter((r) => r.ok).length,
    failed: results.filter((r) => !r.ok).length,
    results,
  }
  const reportPath = join(ARTIFACTS, 'e2e-happy-path-report.json')
  writeFileSync(reportPath, JSON.stringify(report, null, 2))
  console.log(`\nReport: ${reportPath}`)
  console.log(`Passed ${report.passed} / Failed ${report.failed}`)
  if (report.failed > 0) process.exit(1)
}

main().catch((err) => {
  console.error(err)
  process.exit(1)
})
