import { civilDateOnly } from '../src/shared/lib/dates.ts'

const cases = [
  ['2026-10-31T22:00:00.000000Z', '2026-11-01'],
  ['2027-09-07T21:00:00.000000Z', '2027-09-08'],
  ['2023-12-31T22:00:00.000000Z', '2024-01-01'],
  ['2026-11-01T00:00:00+02:00', '2026-11-01'],
  ['2026-07-01T21:30:00Z', '2026-07-02'],
  ['2026-11-01', '2026-11-01'],
  ['2026-11-01 00:00:00', '2026-11-01'],
  [null, ''],
  ['', ''],
]

let failed = 0
for (const [input, expected] of cases) {
  const actual = civilDateOnly(input)
  if (actual !== expected) {
    failed++
    console.error('FAIL', JSON.stringify(input), 'expected', expected, 'got', actual)
  }
}
if (failed) {
  process.exit(1)
}
console.log(`civilDateOnly: ${cases.length} cases ok`)
