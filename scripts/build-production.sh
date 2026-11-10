#!/usr/bin/env bash
# يجهّز حزمة كاملة جاهزة لرفع cPanel (فرونت + باك + vendor):
#   dist/cpanel/          ← محتوى public_html (SPA + index.php + .htaccess)
#   dist/cpanel-app/      ← Laravel كامل مع vendor (خارج الويب)
#   dist/rpm-cpanel.tar.gz ← أرشيف واحد اختياري للرفع اليدوي
#
# لا يحتاج السيرفر Node ولا npm — البناء هنا (محلياً أو في CI).
# Composer على السيرفر غير مطلوب إن رُفع vendor ضمن الحزمة (الافتراضي).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/cpanel"
APP_OUT="$ROOT/dist/cpanel-app"
INCLUDE_VENDOR="${INCLUDE_VENDOR:-1}"
MAKE_TARBALL="${MAKE_TARBALL:-1}"

echo "==> تنظيف المخرجات"
rm -rf "$ROOT/dist"
mkdir -p "$OUT" "$APP_OUT"

echo "==> بناء واجهة PWA (Node محلياً/CI فقط)"
cd "$ROOT/ui-pwa"
if [[ ! -d node_modules ]]; then
  npm ci
fi
npm run build

echo "==> نسخ كود Laravel إلى الحزمة"
cd "$ROOT/backend"
tar -cf - \
  --exclude='.env' \
  --exclude='.env.local' \
  --exclude='.env.production' \
  --exclude='.env.backup' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/app/private/media/*' \
  --exclude='bootstrap/cache/*.php' \
  --exclude='tests' \
  --exclude='phpunit.xml' \
  --exclude='.phpunit.result.cache' \
  . | tar -xf - -C "$APP_OUT"
# تأكد من وجود قوالب البيئة (لا تُستبعد .env.example)
cp -f "$ROOT/backend/.env.example" "$APP_OUT/.env.example"
cp -f "$ROOT/scripts/env.cpanel.example" "$APP_OUT/env.cpanel.example"

# أعِد مجلدات التخزين الفارغة المطلوبة
mkdir -p \
  "$APP_OUT/storage/logs" \
  "$APP_OUT/storage/framework/cache" \
  "$APP_OUT/storage/framework/sessions" \
  "$APP_OUT/storage/framework/views" \
  "$APP_OUT/storage/app/private/media" \
  "$APP_OUT/bootstrap/cache"
for g in storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache; do
  if [[ -f "$ROOT/backend/$g/.gitignore" ]]; then
    cp "$ROOT/backend/$g/.gitignore" "$APP_OUT/$g/.gitignore"
  fi
done
# حافظ على مكان media
touch "$APP_OUT/storage/app/private/media/.gitkeep"

if [[ "$INCLUDE_VENDOR" == "1" ]]; then
  echo "==> تثبيت Composer --no-dev داخل الحزمة (بلا Node على السيرفر)"
  cd "$APP_OUT"
  # انسخ lock من المصدر لضمان نفس الإصدارات
  cp "$ROOT/backend/composer.lock" "$APP_OUT/composer.lock"
  cp "$ROOT/backend/composer.json" "$APP_OUT/composer.json"
  composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-progress
else
  echo "==> تخطّي vendor (INCLUDE_VENDOR=0) — ستحتاج composer install على السيرفر"
fi

echo "==> دمج SPA داخل public_html"
# أصول Laravel public (favicon …) ثم واجهة Vite فوقها
if [[ -d "$APP_OUT/public" ]]; then
  cp -a "$APP_OUT/public/." "$OUT/"
fi
cp -a "$ROOT/ui-pwa/dist/." "$OUT/"
cp "$ROOT/scripts/cpanel-index.php" "$OUT/index.php"
cp "$ROOT/scripts/cpanel.htaccess" "$OUT/.htaccess"
# لا تنشئ مجلد api هنا — يمنع /api/* على Apache
rm -rf "$OUT/api"
# لا نترك .env في public
rm -f "$OUT/.env" "$OUT/.env.example"

# ملف إرشادات بجانب الحزمة
cat > "$ROOT/dist/README-DEPLOY.txt" <<'EOF'
حزمة رفع cPanel الكاملة — RPM (فرونت + باك)
============================================

المحتوى
-------
- cpanel/       → ارفعه إلى public_html/
- cpanel-app/   → ارفعه إلى ~/rpm/  (خارج الويب)
- rpm-cpanel.tar.gz (إن وُجد) → أرشيف الاثنين معاً

نشر سريع عبر SSH (مفضّل — بلا Node على السيرفر)
-----------------------------------------------
من جهازك أو CI بعد ضبط المتغيرات:

  export CPANEL_SSH_HOST=your.domain.com
  export CPANEL_SSH_USER=cpaneluser
  # اختياري: CPANEL_SSH_PORT=22  CPANEL_SSH_KEY=~/.ssh/id_rsa
  bash scripts/deploy-cpanel-ssh.sh

السكربت يبني الحزمة ويرفع الفرونت والباك بـ rsync ثم يشغّل migrate/cache عبر SSH.

رفع يدوي
--------
1) ارفع محتويات cpanel-app/ → /home/USER/rpm/
2) ارفع محتويات cpanel/ → /home/USER/public_html/
3) تأكد أن public_html/index.php فيه: $laravelRoot = __DIR__.'/../rpm';
4) انسخ .env.example → ~/rpm/.env واملأ MySQL + APP_URL
5) عبر SSH:
   cd ~/rpm
   php artisan key:generate --force          # أول مرة
   php artisan migrate --force
   php artisan db:seed --force               # أول تثبيت فقط
   php artisan config:cache && php artisan route:cache
   chmod -R 775 storage bootstrap/cache

6) Cron كل دقيقة:
   * * * * * cd /home/USER/rpm && php artisan schedule:run >> /dev/null 2>&1

لا تحتاج Node على الاستضافة. vendor مضمّن في الحزمة افتراضياً.

راجع README.md القسم «الرفع على cPanel».
EOF

if [[ "$MAKE_TARBALL" == "1" ]]; then
  echo "==> إنشاء أرشيف rpm-cpanel.tar.gz"
  tar -czf "$ROOT/dist/rpm-cpanel.tar.gz" -C "$ROOT/dist" cpanel cpanel-app README-DEPLOY.txt
fi

echo "==> تم: dist/cpanel + dist/cpanel-app$([ "$MAKE_TARBALL" = 1 ] && echo ' + dist/rpm-cpanel.tar.gz')"
du -sh "$OUT" "$APP_OUT" ${MAKE_TARBALL:+"$ROOT/dist/rpm-cpanel.tar.gz"} 2>/dev/null || true
