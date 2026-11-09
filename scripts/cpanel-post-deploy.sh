#!/usr/bin/env bash
# أوامر ما بعد الرفع — تُنفَّذ على السيرفر داخل مجلد Laravel (~/rpm).
# الاستخدام:
#   SEED_FIRST=1 bash cpanel-post-deploy.sh /home/USER/rpm
set -euo pipefail

APP_DIR="${1:-.}"
SEED_FIRST="${SEED_FIRST:-0}"
cd "$APP_DIR"

if [[ ! -f artisan ]]; then
  echo "خطأ: لا يوجد artisan في $(pwd)" >&2
  exit 1
fi

mkdir -p \
  storage/logs \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/app/private/media \
  bootstrap/cache

chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

if [[ ! -f .env ]]; then
  if [[ -f .env.example ]]; then
    cp .env.example .env
    echo "تنبيه: تم إنشاء .env من المثال — املأ MySQL و APP_URL ثم أعد التشغيل."
  else
    echo "خطأ: لا يوجد .env ولا .env.example" >&2
    exit 1
  fi
fi

# أنشئ المفتاح إن كان فارغاً
if ! grep -qE '^APP_KEY=base64:.+' .env; then
  php artisan key:generate --force
fi

# امسح كاش قديم قبل migrate (مهم بعد أول .env)
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

php artisan migrate --force

if [[ "$SEED_FIRST" == "1" ]]; then
  echo "==> SEED_FIRST: تعبئة بيانات العرض + admin@rpm.local"
  php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache 2>/dev/null || true
php artisan event:cache 2>/dev/null || true

# صفّر الطابور إن وُجدت مهام معلّقة (بديل worker دائم على cPanel)
php artisan queue:work --stop-when-empty --max-time=60 2>/dev/null || true

echo "OK: post-deploy اكتمل في $(pwd) (SEED_FIRST=${SEED_FIRST})"
