#!/usr/bin/env bash
# يُشغَّل من Terminal في cPanel بعد رفع الحزمة (أول تثبيت أو تحديث).
# الاستخدام:
#   cd ~/rpm
#   bash cpanel-post-deploy.sh          # تحديث
#   bash cpanel-post-deploy.sh --first  # أول تثبيت (key + seed اختياري)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

FIRST=0
if [[ "${1:-}" == "--first" ]]; then
  FIRST=1
fi

if [[ ! -f artisan ]]; then
  echo "شغّل السكربت من مجلد Laravel (مثلاً ~/rpm)."
  exit 1
fi

if [[ ! -f .env ]]; then
  if [[ -f .env.example ]]; then
    cp .env.example .env
    echo "تم إنشاء .env من .env.example — عبّئ DB_* و APP_URL قبل المتابعة."
    echo "ثم أعد تشغيل: bash cpanel-post-deploy.sh --first"
    exit 1
  fi
  echo "ملف .env غير موجود."
  exit 1
fi

mkdir -p \
  storage/logs \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/app/private/media \
  bootstrap/cache

chmod -R ug+rwx storage bootstrap/cache || true

if [[ "$FIRST" -eq 1 ]]; then
  if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
  fi
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

# تفريغ طابور قصير (بديل worker دائم على cPanel)
php artisan queue:work --stop-when-empty --max-time=60 || true

if [[ "$FIRST" -eq 1 && "${RPM_RUN_SEED:-}" == "yes" ]]; then
  php artisan db:seed --force
  echo "تم الـ seed. غيّر كلمة مرور admin@rpm.local فوراً."
elif [[ "$FIRST" -eq 1 ]]; then
  echo "تخطي db:seed (للتشغيل: RPM_RUN_SEED=yes bash cpanel-post-deploy.sh --first)"
fi

echo "تم: migrate + caches + queue drain."
echo "تحقق: افتح الدومين ثم /up و /api/login"
