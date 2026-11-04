/**
 * Layout / filter-tab audit against a fully seeded DB.
 * Usage: node scripts/e2e-seeded-ui-audit.mjs
 */
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const puppeteer = require('puppeteer')

const BASE = process.env.BASE_URL || 'http://127.0.0.1:4173'
const API = process.env.API_URL || 'http://127.0.0.1:8000/api'
const OUT = process.env.ARTIFACT_DIR || '/opt/cursor/artifacts'
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@rpm.local'
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'password'

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

const PAGES = [
  { name: 'dashboard', path: '/' },
  { name: 'vehicles', path: '/vehicles', expectAllTab: true },
  { name: 'bookings', path: '/bookings', expectAllTab: true },
  { name: 'customers', path: '/customers', expectAllTab: true },
  { name: 'maintenance', path: '/maintenance', expectAllTab: true },
  { name: 'inventory', path: '/inventory', expectAllTab: true },
  { name: 'expenses', path: '/expenses', expectAllTab: true },
  { name: 'violations', path: '/violations', expectAllTab: true },
  { name: 'hr', path: '/hr', expectAllTab: true },
  { name: 'owners', path: '/owners', expectAllTab: true },
  { name: 'notifications', path: '/notifications', expectAllTab: true },
  { name: 'reports', path: '/reports' },
  { name: 'audit', path: '/audit-logs' },
  { name: 'petty-cash', path: '/petty-cash' },
  { name: 'accounts', path: '/accounts' },
]

fs.mkdirSync(OUT, { recursive: true })

async function apiLogin() {
  const res = await fetch(`${API}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
  })
  if (!res.ok) throw new Error(`API login failed ${res.status}: ${await res.text()}`)
  return res.json()
}

function overflowReport(page) {
  return page.evaluate(() => {
    const doc = document.documentElement
    const body = document.body
    const horizontal = Math.max(doc.scrollWidth, body.scrollWidth) > Math.ceil(window.innerWidth) + 2
    const clipped = []
    const nodes = Array.from(
      document.querySelectorAll('h1,h2,h3,td,th,button,.db-kpi__value,.shell-user__meta strong'),
    )
    for (const el of nodes.slice(0, 200)) {
      const style = window.getComputedStyle(el)
      if (style.overflow === 'hidden' || style.textOverflow === 'ellipsis') continue
      if (el.scrollWidth > el.clientWidth + 2 && el.clientWidth > 40) {
        clipped.push({
          tag: el.tagName,
          text: (el.textContent || '').trim().slice(0, 60),
          scroll: el.scrollWidth,
          client: el.clientWidth,
        })
      }
    }
    return { horizontal, clipped: clipped.slice(0, 8) }
  })
}

async function firstFilterTabLabel(page) {
  return page.evaluate(() => {
    const bar =
      document.querySelector('.rpm-filters') ||
      document.querySelector('[aria-label*="تصفية"], [aria-label*="فلتر"]') ||
      document.querySelector('[role="tablist"]')
    if (!bar) return null
    const chips = bar.querySelectorAll('button, a, [role="tab"]')
    if (!chips.length) return null
    const first = chips[0]
    const active = bar.querySelector('.is-active, [aria-selected="true"], [data-active="true"]')
    return {
      first: (first.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40),
      active: active ? (active.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) : null,
      count: chips.length,
    }
  })
}

const auth = await apiLogin()
const token = auth.token || auth.data?.token
if (!token) throw new Error('No token from login')

const browser = await puppeteer.launch({
  headless: 'new',
  executablePath: process.env.CHROME_PATH || '/usr/local/bin/google-chrome',
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900'],
  defaultViewport: { width: 1440, height: 900 },
})

const page = await browser.newPage()
const findings = []

try {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 60000 })
  await page.evaluate((t) => {
    localStorage.setItem('rpm_token', t)
  }, token)
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle2', timeout: 60000 })
  await sleep(1200)
  await page.screenshot({ path: path.join(OUT, 'e2e-audit-home.png'), fullPage: true })

  for (const entry of PAGES) {
    const url = `${BASE}${entry.path}`
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => null)
    await sleep(1000)
    const shot = path.join(OUT, `e2e-audit-${entry.name}.png`)
    await page.screenshot({ path: shot, fullPage: true })
    const overflow = await overflowReport(page)
    const tabs = entry.expectAllTab ? await firstFilterTabLabel(page) : null
    const bodyText = await page.evaluate(() => (document.body?.innerText || '').slice(0, 800))
    const emptyish =
      /لا توجد بيانات|لا يوجد سجلات|القائمة فارغة/i.test(bodyText) &&
      !/(CNT-|C-\d|VEH-|محمود|مخالفة|سلفة|أحمد|هيونداي)/.test(bodyText)
    const allOk =
      !entry.expectAllTab ||
      (tabs && (String(tabs.first).includes('الكل') || String(tabs.active || '').includes('الكل')))
    findings.push({
      page: entry.name,
      path: entry.path,
      shot,
      horizontalOverflow: overflow.horizontal,
      clippedSamples: overflow.clipped,
      tabs,
      allTabOk: allOk,
      emptyish,
    })
  }

  const vehRes = await fetch(`${API}/vehicles?per_page=5`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
  const vehJson = await vehRes.json().catch(() => ({}))
  const firstVehicleId = vehJson?.data?.[0]?.id || vehJson?.data?.data?.[0]?.id
  if (firstVehicleId) {
    const pathOnly = `/vehicles/${firstVehicleId}`
    await page.goto(`${BASE}${pathOnly}`, { waitUntil: 'networkidle2' })
    await sleep(1600)
    await page.screenshot({ path: path.join(OUT, 'e2e-audit-vehicle-detail.png'), fullPage: true })
    const chartOrder = await page.evaluate(() => {
      const root = document.querySelector('.vd-page, main, .shell-main') || document.body
      const charts = root.querySelector('.vd-charts, .vd-chart-kpis')
      if (!charts) return { foundCharts: false, orderOk: false }
      const all = Array.from(root.querySelectorAll('section, .vd-panel, .vd-charts'))
      const chartIdx = all.findIndex((p) => p === charts || p.contains(charts))
      return { foundCharts: true, orderOk: chartIdx >= 0 && chartIdx <= 2, chartIdx }
    })
    findings.push({ page: 'vehicle-detail', path: pathOnly, chartOrder })
  } else {
    findings.push({ page: 'vehicle-detail', path: null, chartOrder: { foundCharts: false, orderOk: false } })
  }

  await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true })
  for (const entry of ['dashboard', 'vehicles', 'bookings', 'hr']) {
    const route = PAGES.find((p) => p.name === entry)?.path || '/'
    await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle2' })
    await sleep(800)
    await page.screenshot({ path: path.join(OUT, `e2e-audit-mobile-${entry}.png`), fullPage: true })
    const overflow = await overflowReport(page)
    findings.push({
      page: `mobile-${entry}`,
      horizontalOverflow: overflow.horizontal,
      clippedSamples: overflow.clipped,
    })
  }
} finally {
  await browser.close()
}

const reportPath = path.join(OUT, 'e2e-seeded-ui-audit.json')
fs.writeFileSync(reportPath, JSON.stringify(findings, null, 2))
const failures = findings.filter(
  (f) =>
    f.horizontalOverflow ||
    f.allTabOk === false ||
    f.emptyish === true ||
    (f.chartOrder && f.chartOrder.foundCharts && !f.chartOrder.orderOk),
)
console.log(
  JSON.stringify(
    {
      reportPath,
      pages: findings.length,
      failures: failures.length,
      failuresDetail: failures,
      summary: findings.map((f) => ({
        page: f.page,
        allTabOk: f.allTabOk,
        horizontalOverflow: f.horizontalOverflow,
        emptyish: f.emptyish,
        tabs: f.tabs,
        chartOrder: f.chartOrder,
      })),
    },
    null,
    2,
  ),
)
process.exit(failures.length ? 1 : 0)
