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

const login = await fetch(`${API}/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@rpm.local', password: 'password' }),
}).then((r) => r.json())
const token = login.token
if (!token) throw new Error('login failed')

const browser = await puppeteer.launch({
  headless: 'new',
  executablePath: process.env.CHROME_PATH || '/usr/local/bin/google-chrome',
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=390,844'],
  defaultViewport: { width: 390, height: 844, isMobile: true, hasTouch: true },
})
const page = await browser.newPage()
const findings = []

function measureOverflow() {
  return page.evaluate(() => {
    const doc = document.documentElement
    const body = document.body
    return {
      scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
      clientWidth: doc.clientWidth,
      overflow: Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 2,
    }
  })
}

try {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
  await page.evaluate((t) => localStorage.setItem('rpm_token', t), token)

  // HR actions
  await page.goto(`${BASE}/hr`, { waitUntil: 'networkidle2' })
  await sleep(1000)
  await page.screenshot({ path: path.join(OUT, 'mobile-hr-actions.png'), fullPage: false })
  const hrOverflow = await measureOverflow()
  const hrActions = await page.evaluate(() => {
    const wrap = document.querySelector('.hr-page .rpm-page-header__action')
    if (!wrap) return null
    const btns = [...wrap.querySelectorAll('.rpm-btn')].map((b) => ({
      text: (b.textContent || '').trim(),
      w: b.getBoundingClientRect().width,
      clipped: b.scrollWidth > b.clientWidth + 1,
    }))
    const style = getComputedStyle(wrap)
    return {
      display: style.display,
      overflowX: style.overflowX,
      btnCount: btns.length,
      btns,
      wrapOverflow: wrap.scrollWidth > wrap.clientWidth + 2,
    }
  })
  findings.push({ page: 'hr', hrOverflow, hrActions })

  // Reports overflow
  await page.goto(`${BASE}/reports`, { waitUntil: 'networkidle2' })
  await sleep(1000)
  await page.screenshot({ path: path.join(OUT, 'mobile-reports.png'), fullPage: true })
  const reportsOverflow = await measureOverflow()
  findings.push({ page: 'reports', reportsOverflow })

  // Drawer header
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' })
  await sleep(800)
  const opened = await page.evaluate(() => {
    const btn =
      document.querySelector('button[aria-label="القائمة"]') ||
      [...document.querySelectorAll('.shell-dock__item, button')].find((el) =>
        /المزيد|أقسام|قائمة/.test(`${el.textContent || ''} ${el.getAttribute('aria-label') || ''}`),
      )
    if (btn) {
      btn.click()
      return true
    }
    return false
  })
  await sleep(900)
  await page.screenshot({ path: path.join(OUT, 'mobile-drawer-header.png'), fullPage: false })
  const drawer = await page.evaluate(() => {
    const head = document.querySelector('.shell-drawer__head')
    if (!head) return { open: false }
    const title = head.querySelector('.shell-drawer__title')
    const logo = head.querySelector('.rpm-logo, .shell-drawer__brand')
    const tr = title?.getBoundingClientRect()
    const lr = logo?.getBoundingClientRect()
    const hr = head.getBoundingClientRect()
    const overlap = tr && lr ? !(tr.right <= lr.left || lr.right <= tr.left || tr.bottom <= lr.top || lr.bottom <= tr.top) : null
    return {
      open: true,
      titleText: title?.textContent?.trim(),
      titleRightAligned: tr ? Math.abs(tr.right - hr.right) < 8 || tr.right >= hr.right - 12 : false,
      titleX: tr && { left: tr.left, right: tr.right },
      logoX: lr && { left: lr.left, right: lr.right, center: (lr.left + lr.right) / 2 },
      headCenter: (hr.left + hr.right) / 2,
      overlap,
      headWidth: hr.width,
    }
  })
  findings.push({ page: 'drawer', opened, drawer })
} finally {
  await browser.close()
}

const report = path.join(OUT, 'mobile-layout-verify.json')
fs.writeFileSync(report, JSON.stringify(findings, null, 2))
const bad = findings.filter(
  (f) =>
    f.hrOverflow?.overflow ||
    f.reportsOverflow?.overflow ||
    f.hrActions?.btns?.some((b) => b.clipped) ||
    f.drawer?.overlap === true,
)
console.log(JSON.stringify({ report, bad: bad.length, findings }, null, 2))
process.exit(bad.length ? 1 : 0)
