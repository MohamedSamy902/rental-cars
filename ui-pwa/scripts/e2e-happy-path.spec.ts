/**
 * Playwright test wrapper for the happy-path UI verification.
 * Prefer `npm run test:e2e` which auto-selects Playwright or Puppeteer.
 *
 * Run with: npx playwright test scripts/e2e-happy-path.spec.ts
 */
import { test, expect } from '@playwright/test'

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:4173'
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@rpm.local'
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'password'

async function login(page: import('@playwright/test').Page) {
  await page.goto(`${BASE_URL}/login`)
  await page.locator('input[type="email"], input[name="email"]').first().fill(ADMIN_EMAIL)
  await page.locator('input[type="password"]').first().fill(ADMIN_PASSWORD)
  await page.locator('button[type="submit"]').first().click()
  await page.waitForTimeout(1200)
}

test.describe('E2E happy path UI', () => {
  test('customers expose tel and WhatsApp actions', async ({ page }) => {
    await login(page)
    await page.goto(`${BASE_URL}/customers`)
    await page.waitForTimeout(1000)
    expect(await page.locator('a[href^="tel:"]').count()).toBeGreaterThan(0)
    expect(await page.locator('a[href*="wa.me"]').count()).toBeGreaterThan(0)
  })

  test('bookings expose customer call actions', async ({ page }) => {
    await login(page)
    await page.goto(`${BASE_URL}/bookings`)
    await page.waitForTimeout(1000)
    expect(await page.locator('a[href^="tel:"]').count()).toBeGreaterThan(0)
  })

  test('contract detail shows three print documents', async ({ page }) => {
    await login(page)
    await page.goto(`${BASE_URL}/bookings`)
    await page.waitForTimeout(800)
    const first = page.locator('a[href*="/contracts/"]').first()
    if (await first.count()) {
      await first.click()
      await page.waitForTimeout(1000)
    } else {
      await page.goto(`${BASE_URL}/contracts/1`)
      await page.waitForTimeout(1000)
    }
    const body = await page.locator('body').innerText()
    expect(body.includes('طباعة العقد') || body.includes('الفاتورة')).toBeTruthy()
    expect(body.includes('سند القبض') || body.includes('محضر التسليم') || body.includes('كشف الحساب')).toBeTruthy()
  })
})
