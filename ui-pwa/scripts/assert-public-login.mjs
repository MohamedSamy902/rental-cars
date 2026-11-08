/**
 * Fail if a production bundle still publishes a login demo helper.
 * Prints only pass/fail, never file contents.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const dist = new URL('../dist', import.meta.url)
const root = dist.pathname
const forbidden = ['auth-demo', 'const DEMO', 'VITE_ENABLE_DEMO_LOGIN']

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    const stat = statSync(path)
    if (stat.isDirectory()) walk(path, out)
    else if (/\.(js|css|html|map)$/.test(name)) out.push(path)
  }
  return out
}

let files
try {
  files = walk(root)
} catch {
  console.error('public-login check: dist is missing')
  process.exit(1)
}

const hits = []
for (const file of files) {
  const text = readFileSync(file, 'utf8')
  for (const marker of forbidden) {
    if (text.includes(marker)) hits.push(marker)
  }
}

if (hits.length > 0) {
  console.error(`public-login check failed: ${[...new Set(hits)].join(', ')}`)
  process.exit(1)
}

console.log(`public-login check passed (${files.length} files)`)
