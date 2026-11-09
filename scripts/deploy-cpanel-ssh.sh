#!/usr/bin/env bash
# نشر كامل (فرونت + باك + vendor) إلى cPanel عبر SSH/rsync.
# لا يشغّل Node على السيرفر — البناء محلياً أو من حزمة جاهزة.
#
# مطلوب:
#   CPANEL_SSH_HOST   مثال: example.com أو IP
#   CPANEL_SSH_USER   مستخدم cPanel
# اختياري:
#   CPANEL_SSH_PORT=22
#   CPANEL_SSH_KEY=~/.ssh/id_rsa          # أو اترك ssh-agent
#   CPANEL_APP_DIR=rpm                    # نسبي لـ $HOME أو مسار مطلق
#   CPANEL_PUBLIC_DIR=public_html
#   SKIP_BUILD=0                          # 1 = استخدم dist/ الموجود
#   RUN_MIGRATE=1                         # 0 = ارفع فقط بلا artisan
#   SEED_FIRST=0                          # 1 = php artisan db:seed --force (أول تثبيت)
#   DRY_RUN=0                             # 1 = rsync --dry-run
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

SSH_OPTS=(-p "$CPANEL_SSH_PORT" -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30)
RSYNC_SSH="ssh -p $CPANEL_SSH_PORT -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30"
if [[ -n "${CPANEL_SSH_KEY:-}" ]]; then
  KEY_PATH="${CPANEL_SSH_KEY/#\~/$HOME}"
  SSH_OPTS+=(-i "$KEY_PATH")
  RSYNC_SSH="$RSYNC_SSH -i $KEY_PATH"
fi

REMOTE="${CPANEL_SSH_USER}@${CPANEL_SSH_HOST}"
RSYNC_FLAGS=(-az --human-readable --info=stats2)
if [[ "$DRY_RUN" == "1" ]]; then
  RSYNC_FLAGS+=(--dry-run)
  echo "==> DRY_RUN=1 — لن يُكتب شيء على السيرفر"
fi

if [[ "$SKIP_BUILD" != "1" ]]; then
  echo "==> بناء الحزمة الكاملة (فرونت + باك)"
  bash "$ROOT/scripts/build-production.sh"
else
  echo "==> تخطّي البناء — استخدام dist/ الحالي"
fi

APP_LOCAL="$ROOT/dist/cpanel-app"
WEB_LOCAL="$ROOT/dist/cpanel"
[[ -d "$APP_LOCAL" && -d "$WEB_LOCAL" ]] || {
  echo "خطأ: الحزمة غير موجودة في dist/ — شغّل build أو ألغِ SKIP_BUILD" >&2
  exit 1
}
[[ -f "$APP_LOCAL/artisan" ]] || { echo "خطأ: dist/cpanel-app بلا artisan" >&2; exit 1; }
[[ -f "$WEB_LOCAL/index.php" && -f "$WEB_LOCAL/index.html" ]] || {
  echo "خطأ: dist/cpanel ناقص (يلزم index.php + index.html من SPA)" >&2
  exit 1
}

echo "==> التأكد من المجلدات البعيدة"
ssh "${SSH_OPTS[@]}" "$REMOTE" "mkdir -p \"\$HOME/${CPANEL_APP_DIR}\" \"\$HOME/${CPANEL_PUBLIC_DIR}\""

echo "==> رفع الباك (Laravel + vendor) → ~/${CPANEL_APP_DIR}/"
# لا تمس .env ولا ملفات المستخدم المرفوعة ولا اللوجات
rsync "${RSYNC_FLAGS[@]}" \
  --delete \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude 'storage/logs/**' \
  --exclude 'storage/framework/cache/**' \
  --exclude 'storage/framework/sessions/**' \
  --exclude 'storage/framework/views/**' \
  --exclude 'storage/app/private/media/**' \
  --exclude 'bootstrap/cache/*.php' \
  -e "$RSYNC_SSH" \
  "$APP_LOCAL/" \
  "${REMOTE}:~/${CPANEL_APP_DIR}/"

echo "==> رفع الفرونت (SPA + نقطة الدخول) → ~/${CPANEL_PUBLIC_DIR}/"
rsync "${RSYNC_FLAGS[@]}" \
  --delete \
  --exclude '.env' \
  --exclude '.user.ini' \
  --exclude 'cgi-bin/**' \
  --exclude '.well-known/**' \
  -e "$RSYNC_SSH" \
  "$WEB_LOCAL/" \
  "${REMOTE}:~/${CPANEL_PUBLIC_DIR}/"

if [[ "$DRY_RUN" == "1" ]]; then
  echo "==> انتهى dry-run"
  exit 0
fi

# ارفع سكربت ما بعد النشر ونفّذه
echo "==> ضبط صلاحيات + migrate/cache عبر SSH"
REMOTE_POST="\$HOME/${CPANEL_APP_DIR}/cpanel-post-deploy.sh"
scp -P "$CPANEL_SSH_PORT" ${CPANEL_SSH_KEY:+-i "${CPANEL_SSH_KEY/#\~/$HOME}"} \
  "$ROOT/scripts/cpanel-post-deploy.sh" \
  "${REMOTE}:${CPANEL_APP_DIR}/cpanel-post-deploy.sh"

if [[ "$RUN_MIGRATE" == "1" ]]; then
  ssh "${SSH_OPTS[@]}" "$REMOTE" \
    "SEED_FIRST=${SEED_FIRST} bash \"\$HOME/${CPANEL_APP_DIR}/cpanel-post-deploy.sh\" \"\$HOME/${CPANEL_APP_DIR}\""
else
  echo "==> تخطّي migrate (RUN_MIGRATE=0)"
fi

echo ""
echo "تم النشر الكامل عبر SSH."
echo "  باك:  ~/${CPANEL_APP_DIR}/"
echo "  فرونت: ~/${CPANEL_PUBLIC_DIR}/"
echo "تحقق: https://${CPANEL_SSH_HOST}/  و  https://${CPANEL_SSH_HOST}/up"
echo "Cron (مرة واحدة من cPanel إن لم يكن مضبوطاً):"
echo "  * * * * * cd \$HOME/${CPANEL_APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
