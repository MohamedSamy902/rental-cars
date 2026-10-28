#!/usr/bin/env bash
# يجهّز حزمة جاهزة لرفع cPanel:
#   dist/cpanel/          ← محتوى public_html (واجهة + نقطة دخول Laravel)
#   dist/cpanel-app/      ← كود Laravel خارج public (يُرفع بجانب public_html أو فوقه)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/cpanel"
APP_OUT="$ROOT/dist/cpanel-app"

echo "==> تنظيف المخرجات"
rm -rf "$ROOT/dist"
mkdir -p "$OUT" "$APP_OUT"

echo "==> بناء واجهة PWA"
cd "$ROOT/ui-pwa"
if [[ ! -d node_modules ]]; then
  npm ci
fi
npm run build

echo "==> تجهيز Laravel بدون vendor (يُثبَّت على السيرفر أو يُرفع جاهزاً)"
cd "$ROOT/backend"
tar -cf - \
  --exclude='.env' \
  --exclude='node_modules' \
  --exclude='storage/logs' \
  --exclude='storage/framework/cache' \
  --exclude='storage/framework/sessions' \
  --exclude='storage/framework/views' \
  --exclude='storage/app/private/media' \
  --exclude='bootstrap/cache/*.php' \
  . | tar -xf - -C "$APP_OUT"

# أعِد مجلدات التخزين الفارغة المطلوبة
mkdir -p \
  "$APP_OUT/storage/logs" \
  "$APP_OUT/storage/framework/cache" \
  "$APP_OUT/storage/framework/sessions" \
  "$APP_OUT/storage/framework/views" \
  "$APP_OUT/storage/app/private/media" \
  "$APP_OUT/bootstrap/cache"
# حافظ على .gitignore إن وُجدت في المصدر
for g in storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache; do
  if [[ -f "$ROOT/backend/$g/.gitignore" ]]; then
    cp "$ROOT/backend/$g/.gitignore" "$APP_OUT/$g/.gitignore"
  fi
done

echo "==> دمج SPA داخل public للرفع على public_html"
cp -a "$APP_OUT/public/." "$OUT/"
cp -a "$ROOT/ui-pwa/dist/." "$OUT/"
cp "$ROOT/scripts/cpanel-index.php" "$OUT/index.php"
cp "$ROOT/scripts/cpanel.htaccess" "$OUT/.htaccess"

# ملف إرشادات بجانب الحزمة
cat > "$ROOT/dist/README-DEPLOY.txt" <<'EOF'
حزمة رفع cPanel — RPM
=====================

1) ارفع محتويات مجلد cpanel-app/ إلى مسار خارج الويب مثلاً:
   /home/USER/rpm   (أو /home/USER/laravel)

2) ارفع محتويات مجلد cpanel/ إلى:
   /home/USER/public_html/

3) عدّل public_html/index.php ليوجّه إلى مسار Laravel الصحيح
   (السطور التي فيها require __DIR__.'/../vendor/...' )
   مثال إن كان التطبيق في /home/USER/rpm:
     require __DIR__.'/../rpm/vendor/autoload.php';
     $app = require_once __DIR__.'/../rpm/bootstrap/app.php';

4) انسخ .env.example إلى /home/USER/rpm/.env واملأ MySQL + APP_URL + APP_KEY

5) من Terminal في cPanel (أو SSH):
   cd ~/rpm
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force   # أول مرة فقط
   php artisan storage:link      # إن لزم للملفات العامة
   chmod -R 775 storage bootstrap/cache

6) Cron يومي/كل دقيقة:
   * * * * * cd /home/USER/rpm && php artisan schedule:run >> /dev/null 2>&1

راجع README.md القسم «الرفع على cPanel» للتفاصيل الكاملة.
EOF

echo "==> تم: dist/cpanel و dist/cpanel-app"
du -sh "$OUT" "$APP_OUT" || true
