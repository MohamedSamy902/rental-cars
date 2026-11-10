#!/usr/bin/env bash
# أوامر ما بعد الرفع — تُنفَّذ على السيرفر داخل مجلد Laravel (~/rpm).
# الاستخدام:
#   SEED_FIRST=1 PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php \
#     bash cpanel-post-deploy.sh /home/USER/rpm
set -euo pipefail

APP_DIR="${1:-.}"
SEED_FIRST="${SEED_FIRST:-0}"
cd "$APP_DIR"

if [[ ! -f artisan ]]; then
  echo "خطأ: لا يوجد artisan في $(pwd)" >&2
  exit 1
fi

# اختر ثنائي PHP المناسب لـ cPanel (web غالباً ea-php84 بينما php الافتراضي أقدم)
resolve_php() {
  if [[ -n "${PHP_BIN:-}" && -x "${PHP_BIN}" ]]; then
    echo "$PHP_BIN"
    return
  fi
  local candidate
  for candidate in \
    /opt/cpanel/ea-php84/root/usr/bin/php \
    /opt/cpanel/ea-php83/root/usr/bin/php \
    /usr/local/bin/php \
    "$(command -v php 2>/dev/null || true)"
  do
    if [[ -n "$candidate" && -x "$candidate" ]]; then
      echo "$candidate"
      return
    fi
  done
  echo "php"
}

PHP_BIN="$(resolve_php)"
echo "==> PHP_BIN=${PHP_BIN}"
"$PHP_BIN" -v

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

if ! grep -qE '^APP_KEY=base64:.+' .env; then
  "$PHP_BIN" artisan key:generate --force
fi

echo "==> مسح الكاش ثم إعادة البناء"
"$PHP_BIN" artisan optimize:clear 2>/dev/null || true
"$PHP_BIN" artisan config:clear 2>/dev/null || true
"$PHP_BIN" artisan route:clear 2>/dev/null || true
"$PHP_BIN" artisan view:clear 2>/dev/null || true
"$PHP_BIN" artisan event:clear 2>/dev/null || true
"$PHP_BIN" artisan cache:clear 2>/dev/null || true

"$PHP_BIN" artisan migrate --force

if [[ "$SEED_FIRST" == "1" ]]; then
  echo "==> SEED_FIRST: تعبئة بيانات العرض + admin@rpm.local"
  "$PHP_BIN" artisan db:seed --force
fi

echo "==> إثراء بيانات الديمو المتنوعة (idempotent)"
"$PHP_BIN" artisan rpm:enrich-live-demo 2>/dev/null || echo "تنبيه: rpm:enrich-live-demo تُخطّي أو غير متاح بعد"

"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache 2>/dev/null || true
"$PHP_BIN" artisan event:cache 2>/dev/null || true
"$PHP_BIN" artisan queue:work --stop-when-empty --max-time=60 2>/dev/null || true

echo "OK: post-deploy اكتمل في $(pwd) (SEED_FIRST=${SEED_FIRST} PHP_BIN=${PHP_BIN})"
