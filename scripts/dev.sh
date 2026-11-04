#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="${HOME}/bin:${PATH}"

echo "==> RPM dev"
cd "$ROOT/backend"
if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate
fi
php artisan migrate --force
php artisan db:seed --force >/dev/null
php artisan serve --host=127.0.0.1 --port=8000 &
BACK_PID=$!

cd "$ROOT/ui-pwa"
if [[ ! -d node_modules ]]; then
  npm install
fi
npm run dev &
FRONT_PID=$!

cleanup() {
  kill "$BACK_PID" "$FRONT_PID" 2>/dev/null || true
}
trap cleanup EXIT

echo "API:  http://127.0.0.1:8000"
echo "PWA:  http://127.0.0.1:3847"
echo "Login: admin@rpm.local / password"
wait
