/**
 * Aggressive layout stress audit against mega-seeded data.
 */
import { createRequire } from 'node:module'
import fs from 'node:fs'
import path from 'node:path'

const require = createRequire(import.meta.url)
const puppeteer = require('puppeteer')

const BASE = process.env.BASE_URL || 'http://127.0.0.1:4173'
const API = process.env.API_URL || 'http://127.0.0.1:8000/api'
const OUT = process.env.ARTIFACT_DIR || '/opt/cursor/artifacts'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
fs.mkdirSync(OUT, { recursive: true })

const PAGES = [
  { name: 'dashboard', path: '/' },
  { name: 'vehicles', path: '/vehicles', expectAll: true },
  { name: 'bookings', path: '/bookings', expectAll: true },
  { name: 'customers', path: '/customers', expectAll: true },
  { name: 'maintenance', path: '/maintenance', expectAll: true },
  { name: 'inventory', path: '/inventory', expectAll: true },
  { name: 'hr', path: '/hr', expectAll: true },
  { name: 'expenses', path: '/expenses', expectAll: true },
  { name: 'violations', path: '/violations', expectAll: true },
  { name: 'owners', path: '/owners', expectAll: true },
  { name: 'waitlist', path: '/waitlist', expectAll: true },
  { name: 'notifications', path: '/notifications', expectAll: true },
  { name: 'reports', path: '/reports' },
  { name: 'audit-logs', path: '/audit-logs' },
  { name: 'petty-cash', path: '/petty-cash' },
  { name: 'accounts', path: '/accounts' },
  { name: 'settings', path: '/settings' },
]

const auth = await fetch(`${API}/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@rpm.local', password: 'password' }),
}).then((r) => r.json())
if (!auth.token) throw new Error('login failed')

const browser = await puppeteer.launch({
  headless: 'new',
  executablePath: process.env.CHROME_PATH || '/usr/local/bin/google-chrome',
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
})

async function auditViewport(label, width, height, isMobile) {
  const page = await browser.newPage()
  await page.setViewport({ width, height, isMobile, hasTouch: isMobile })
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
  await page.evaluate((t) => localStorage.setItem('rpm_token', t), auth.token)
  const findings = []

  for (const entry of PAGES) {
    await page.goto(`${BASE}${entry.path}`, { waitUntil: 'networkidle2', timeout: 90000 }).catch(() => null)
    await sleep(700)
    const shot = path.join(OUT, `mega-stress-${label}-${entry.name}.png`)
    await page.screenshot({ path: shot, fullPage: false })
    const metrics = await page.evaluate(() => {
      const doc = document.documentElement
      const body = document.body
      const overflow = Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 2
      const bar = document.querySelector('.rpm-filters')
      let tabs = null
      if (bar) {
        const chips = [...bar.querySelectorAll('button, a, [role="tab"]')]
        const active = bar.querySelector('.is-active, [aria-selected="true"]')
        tabs = {
          first: chips[0] ? (chips[0].textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) : null,
          active: active ? (active.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) : null,
          count: chips.length,
        }
      }
      const actionBtns = [...document.querySelectorAll('.rpm-page-header__action .rpm-btn')].map((b) => ({
        text: (b.textContent || '').trim().slice(0, 40),
        clipped: b.scrollWidth > b.clientWidth + 2,
      }))
      return {
        overflow,
        scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
        clientWidth: doc.clientWidth,
        tabs,
        actionBtns,
        emptyHeavy: /لا توجد بيانات|لا يوجد سجلات/.test(body.innerText || '') && !(body.innerText || '').match(/CNT-|MG-|سلف|مخالفة/),
      }
    })
    const allOk =
      !entry.expectAll ||
      (metrics.tabs && (String(metrics.tabs.first).includes('الكل') || String(metrics.tabs.active || '').includes('الكل')))
    findings.push({
      viewport: label,
      page: entry.name,
      path: entry.path,
      shot,
      allTabOk: allOk,
      ...metrics,
    })
  }

  await page.close()
  return findings
}

const desktop = await auditViewport('desk', 1440, 900, false)
const mobile = await auditViewport('mobile', 390, 844, true)
await browser.close()

const all = [...desktop, ...mobile]
const failures = all.filter(
  (f) => f.overflow || f.allTabOk === false || f.emptyHeavy || f.actionBtns?.some((b) => b.clipped),
)
const reportPath = path.join(OUT, 'mega-ui-stress-audit.json')
fs.writeFileSync(reportPath, JSON.stringify({ failures: failures.length, failuresDetail: failures, all }, null, 2))
console.log(JSON.stringify({
  reportPath,
  pages: all.length,
  failures: failures.length,
  failurePages: failures.map((f) => `${f.viewport}/${f.page}:${f.overflow ? 'overflow' : ''}${f.allTabOk === false ? 'tabs' : ''}${f.actionBtns?.some((b) => b.clipped) ? 'btnclip' : ''}`),
}, null, 2))
process.exit(failures.length ? 1 : 0)
