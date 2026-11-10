#!/usr/bin/env bash
# نشر تلقائي إلى cPanel عبر SSH/rsync (بلا Node على السيرفر).
#
# أوضاع PUBLIC_MODE:
#   api  (افتراضي مع Vercel) — يرفع Laravel + نقطة دخول API فقط في public_html
#   spa  — يرفع SPA كاملة داخل public_html (كل شيء على cPanel)
#
# مطلوب: CPANEL_SSH_HOST · CPANEL_SSH_USER
# اختياري: CPANEL_SSH_PORT · CPANEL_SSH_KEY · CPANEL_APP_DIR · CPANEL_PUBLIC_DIR
#           SKIP_BUILD · RUN_MIGRATE · SEED_FIRST · DRY_RUN · PUBLIC_MODE
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

: "${CPANEL_SSH_HOST:?اضبط CPANEL_SSH_HOST}"
: "${CPANEL_SSH_USER:?اضبط CPANEL_SSH_USER}"

CPANEL_SSH_PORT="${CPANEL_SSH_PORT:-22}"
CPANEL_APP_DIR="${CPANEL_APP_DIR:-rpm}"
CPANEL_PUBLIC_DIR="${CPANEL_PUBLIC_DIR:-public_html}"
SKIP_BUILD="${SKIP_BUILD:-0}"
RUN_MIGRATE="${RUN_MIGRATE:-1}"
SEED_FIRST="${SEED_FIRST:-0}"
DRY_RUN="${DRY_RUN:-0}"
PUBLIC_MODE="${PUBLIC_MODE:-api}"
# على cPanel غالباً: /opt/cpanel/ea-php84/root/usr/bin/php
PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"

SSH_OPTS=(-p "$CPANEL_SSH_PORT" -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30)
RSYNC_SSH="ssh -p $CPANEL_SSH_PORT -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30"
SCP_OPTS=(-P "$CPANEL_SSH_PORT" -o StrictHostKeyChecking=accept-new)
if [[ -n "${CPANEL_SSH_KEY:-}" ]]; then
  KEY_PATH="${CPANEL_SSH_KEY/#\~/$HOME}"
  SSH_OPTS+=(-i "$KEY_PATH")
  SCP_OPTS+=(-i "$KEY_PATH")
  RSYNC_SSH="$RSYNC_SSH -i $KEY_PATH"
fi

REMOTE="${CPANEL_SSH_USER}@${CPANEL_SSH_HOST}"
RSYNC_FLAGS=(-az --human-readable --info=stats2)
if [[ "$DRY_RUN" == "1" ]]; then
  RSYNC_FLAGS+=(--dry-run)
  echo "==> DRY_RUN=1"
fi

if [[ "$SKIP_BUILD" != "1" ]]; then
  echo "==> بناء الحزمة (فرونت للـ SPA mode + باك دائماً)"
  bash "$ROOT/scripts/build-production.sh"
else
  echo "==> تخطّي البناء — dist/ الحالي"
fi

APP_LOCAL="$ROOT/dist/cpanel-app"
WEB_LOCAL="$ROOT/dist/cpanel"
[[ -d "$APP_LOCAL" ]] || { echo "خطأ: لا يوجد dist/cpanel-app" >&2; exit 1; }
[[ -f "$APP_LOCAL/artisan" ]] || { echo "خطأ: بلا artisan" >&2; exit 1; }

echo "==> التأكد من المجلدات البعيدة"
ssh "${SSH_OPTS[@]}" "$REMOTE" "mkdir -p \"\$HOME/${CPANEL_APP_DIR}\" \"\$HOME/${CPANEL_PUBLIC_DIR}\""

echo "==> رفع الباك → ~/${CPANEL_APP_DIR}/"
rsync "${RSYNC_FLAGS[@]}" \
  --delete \
  --exclude '.env' \
  --exclude '.env.local' \
  --exclude '.env.production' \
  --exclude '.env.backup' \
  --exclude 'storage/logs/**' \
  --exclude 'storage/framework/cache/**' \
  --exclude 'storage/framework/sessions/**' \
  --exclude 'storage/framework/views/**' \
  --exclude 'storage/app/private/media/**' \
  --exclude 'bootstrap/cache/*.php' \
  -e "$RSYNC_SSH" \
  "$APP_LOCAL/" \
  "${REMOTE}:~/${CPANEL_APP_DIR}/"

if [[ "$PUBLIC_MODE" == "spa" ]]; then
  [[ -d "$WEB_LOCAL" && -f "$WEB_LOCAL/index.html" ]] || {
    echo "خطأ: PUBLIC_MODE=spa يحتاج dist/cpanel كامل" >&2
    exit 1
  }
  echo "==> رفع SPA كاملة → ~/${CPANEL_PUBLIC_DIR}/"
  rsync "${RSYNC_FLAGS[@]}" \
    --delete \
    --exclude '.env' \
    --exclude '.user.ini' \
    --exclude 'cgi-bin/**' \
    --exclude '.well-known/**' \
    -e "$RSYNC_SSH" \
    "$WEB_LOCAL/" \
    "${REMOTE}:~/${CPANEL_PUBLIC_DIR}/"
else
  echo "==> رفع نقطة دخول API فقط → ~/${CPANEL_PUBLIC_DIR}/ (الفرونت على Vercel)"
  if [[ "$DRY_RUN" != "1" ]]; then
    scp "${SCP_OPTS[@]}" \
      "$ROOT/scripts/cpanel-index.php" \
      "${REMOTE}:${CPANEL_PUBLIC_DIR}/index.php"
    scp "${SCP_OPTS[@]}" \
      "$ROOT/scripts/cpanel-api.htaccess" \
      "${REMOTE}:${CPANEL_PUBLIC_DIR}/.htaccess"
  fi
fi

if [[ "$DRY_RUN" == "1" ]]; then
  echo "==> انتهى dry-run"
  exit 0
fi

echo "==> post-deploy على السيرفر"
scp "${SCP_OPTS[@]}" \
  "$ROOT/scripts/cpanel-post-deploy.sh" \
  "${REMOTE}:${CPANEL_APP_DIR}/cpanel-post-deploy.sh"

if [[ "$RUN_MIGRATE" == "1" ]]; then
  ssh "${SSH_OPTS[@]}" "$REMOTE" \
    "SEED_FIRST=${SEED_FIRST} PHP_BIN='${PHP_BIN}' bash \"\$HOME/${CPANEL_APP_DIR}/cpanel-post-deploy.sh\" \"\$HOME/${CPANEL_APP_DIR}\""
else
  echo "==> تخطّي migrate"
fi

echo ""
echo "تم النشر. PUBLIC_MODE=${PUBLIC_MODE}"
echo "  باك: ~/${CPANEL_APP_DIR}/"
echo "  API: https://${CPANEL_SSH_HOST}/up"
echo "  فرونت (Vercel): يُنشر تلقائياً من GitHub إن كان المشروع مربوطاً"
