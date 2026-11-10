#!/usr/bin/env bash
# تثبيت أول مرة على cPanel عبر SSH — يرفع الفرونت + الباك + vendor
# ثم يكتب .env ويعمل migrate + seed.
#
# لا يحتاج السيرفر Node. البناء على جهازك/CI فقط.
#
# قبل التشغيل من cPanel:
#   1) أنشئ قاعدة MySQL + مستخدم بصلاحيات كاملة
#   2) فعّل SSH و PHP 8.2/8.3 لـ public_html
#   3) جهّز مفتاح SSH من جهازك إلى الاستضافة
#
# مطلوب:
#   CPANEL_SSH_HOST / CPANEL_SSH_USER
#   APP_URL              مثال https://example.com
#   DB_DATABASE / DB_USERNAME / DB_PASSWORD
# اختياري:
#   DB_HOST=localhost  DB_PORT=3306
#   CPANEL_SSH_KEY  CPANEL_SSH_PORT  CPANEL_APP_DIR  CPANEL_PUBLIC_DIR
#   CPANEL_ENV_FILE=./my-prod.env   # إن وُجد يُرفع كما هو بدل التوليد
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

: "${CPANEL_SSH_HOST:?اضبط CPANEL_SSH_HOST}"
: "${CPANEL_SSH_USER:?اضبط CPANEL_SSH_USER}"

CPANEL_SSH_PORT="${CPANEL_SSH_PORT:-22}"
CPANEL_APP_DIR="${CPANEL_APP_DIR:-rpm}"
CPANEL_PUBLIC_DIR="${CPANEL_PUBLIC_DIR:-public_html}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"

SSH_OPTS=(-p "$CPANEL_SSH_PORT" -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30)
SCP_OPTS=(-P "$CPANEL_SSH_PORT" -o StrictHostKeyChecking=accept-new)
if [[ -n "${CPANEL_SSH_KEY:-}" ]]; then
  KEY_PATH="${CPANEL_SSH_KEY/#\~/$HOME}"
  SSH_OPTS+=(-i "$KEY_PATH")
  SCP_OPTS+=(-i "$KEY_PATH")
fi
REMOTE="${CPANEL_SSH_USER}@${CPANEL_SSH_HOST}"

ENV_TMP="$(mktemp)"
cleanup() { rm -f "$ENV_TMP"; }
trap cleanup EXIT

if [[ -n "${CPANEL_ENV_FILE:-}" ]]; then
  [[ -f "$CPANEL_ENV_FILE" ]] || { echo "ملف CPANEL_ENV_FILE غير موجود: $CPANEL_ENV_FILE" >&2; exit 1; }
  cp "$CPANEL_ENV_FILE" "$ENV_TMP"
  echo "==> استخدام .env من $CPANEL_ENV_FILE"
else
  : "${APP_URL:?اضبط APP_URL مثل https://example.com}"
  : "${DB_DATABASE:?اضبط DB_DATABASE}"
  : "${DB_USERNAME:?اضبط DB_USERNAME}"
  : "${DB_PASSWORD:?اضبط DB_PASSWORD}"

  DOMAIN_HOST="${APP_URL#https://}"
  DOMAIN_HOST="${DOMAIN_HOST#http://}"
  DOMAIN_HOST="${DOMAIN_HOST%%/*}"

  sed \
    -e "s|https://YOUR-DOMAIN.com|${APP_URL}|g" \
    -e "s|YOUR-DOMAIN.com|${DOMAIN_HOST}|g" \
    -e "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|" \
    -e "s|^DB_PORT=.*|DB_PORT=${DB_PORT}|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" \
    -e "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME}|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" \
    "$ROOT/scripts/env.cpanel.example" > "$ENV_TMP"
  echo "==> توليد .env للإنتاج من القالب"
fi

echo "==> بناء ورفع الفرونت + الباك (بدون seed بعد — نضبط .env أولاً)"
export CPANEL_SSH_HOST CPANEL_SSH_USER CPANEL_SSH_PORT CPANEL_SSH_KEY
export CPANEL_APP_DIR CPANEL_PUBLIC_DIR
export SEED_FIRST=0
export RUN_MIGRATE=0
bash "$ROOT/scripts/deploy-cpanel-ssh.sh"

echo "==> رفع .env إلى ~/${CPANEL_APP_DIR}/.env (أول تثبيت)"
# لا نكتب فوق .env موجود إلا مع FORCE_ENV=1
REMOTE_HAS_ENV="$(ssh "${SSH_OPTS[@]}" "$REMOTE" "if [ -f \"\$HOME/${CPANEL_APP_DIR}/.env\" ]; then echo yes; else echo no; fi")"
if [[ "$REMOTE_HAS_ENV" == "yes" && "${FORCE_ENV:-0}" != "1" ]]; then
  echo "تنبيه: .env موجود على السيرفر — لم يُستبدل. لفرض الاستبدال: FORCE_ENV=1"
else
  scp "${SCP_OPTS[@]}" "$ENV_TMP" "${REMOTE}:${CPANEL_APP_DIR}/.env"
  echo "تم رفع .env"
fi

echo "==> migrate + seed + cache (تثبيت أول مرة)"
scp "${SCP_OPTS[@]}" \
  "$ROOT/scripts/cpanel-post-deploy.sh" \
  "${REMOTE}:${CPANEL_APP_DIR}/cpanel-post-deploy.sh"
ssh "${SSH_OPTS[@]}" "$REMOTE" \
  "SEED_FIRST=1 bash \"\$HOME/${CPANEL_APP_DIR}/cpanel-post-deploy.sh\" \"\$HOME/${CPANEL_APP_DIR}\""

echo ""
echo "=============================================="
echo "التثبيت الأول اكتمل."
echo "  الواجهة: ${APP_URL:-https://$CPANEL_SSH_HOST}/"
echo "  الصحة:   ${APP_URL:-https://$CPANEL_SSH_HOST}/up"
echo "  دخول:    admin@rpm.local  /  password"
echo "  غيّر كلمة المرور فوراً من شاشة الموظفين."
echo ""
echo "أضف Cron من cPanel (كل دقيقة):"
echo "  * * * * * cd \$HOME/${CPANEL_APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
echo "=============================================="
