#!/usr/bin/env bash
# يجهّز حزمة جاهزة لرفع cPanel (بدون Node على السيرفر):
#   dist/cpanel/          ← محتوى public_html (واجهة SPA + نقطة دخول Laravel)
#   dist/cpanel-app/      ← كود Laravel خارج الويب (مع vendor إنتاجي)
#   dist/README-DEPLOY.txt
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/cpanel"
APP_OUT="$ROOT/dist/cpanel-app"

echo "==> تنظيف المخرجات"
rm -rf "$ROOT/dist"
mkdir -p "$OUT" "$APP_OUT"

echo "==> بناء واجهة PWA (Node على CI/الجهاز فقط)"
cd "$ROOT/ui-pwa"
if [[ ! -d node_modules ]]; then
  npm ci
fi
npm run build

echo "==> تجهيز كود Laravel (بدون vendor محلي/تطوير)"
cd "$ROOT/backend"
tar -cf - \
  --exclude='.env' \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='phpunit.xml' \
  --exclude='.phpunit.result.cache' \
  --exclude='storage/logs' \
  --exclude='storage/framework/cache' \
  --exclude='storage/framework/sessions' \
  --exclude='storage/framework/views' \
  --exclude='storage/app/private/media' \
  --exclude='bootstrap/cache/*.php' \
  . | tar -xf - -C "$APP_OUT"

echo "==> تثبيت vendor إنتاجي داخل الحزمة (لا يعتمد على Composer على cPanel)"
cd "$APP_OUT"
composer install \
  --no-dev \
  --optimize-autoloader \
  --no-interaction \
  --prefer-dist \
  --no-progress

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

# لا تُرفع مفاتيح تطوير فارغة — .env يُنشأ على السيرفر مرة واحدة
rm -f "$APP_OUT/.env"

echo "==> دمج SPA داخل public للرفع على public_html"
cp -a "$APP_OUT/public/." "$OUT/"
cp -a "$ROOT/ui-pwa/dist/." "$OUT/"
cp "$ROOT/scripts/cpanel-index.php" "$OUT/index.php"
cp "$ROOT/scripts/cpanel.htaccess" "$OUT/.htaccess"
cp "$ROOT/scripts/cpanel-post-deploy.sh" "$APP_OUT/cpanel-post-deploy.sh"
chmod +x "$APP_OUT/cpanel-post-deploy.sh"

cat > "$ROOT/dist/README-DEPLOY.txt" <<'EOF'
حزمة رفع cPanel — RPM
=====================

1) ارفع محتويات مجلد cpanel-app/ إلى مسار خارج الويب مثلاً:
   /home/USER/rpm

2) ارفع محتويات مجلد cpanel/ إلى:
   /home/USER/public_html/

3) تأكد أن public_html/index.php يشير إلى:
   $laravelRoot = __DIR__.'/../rpm';

4) أول مرة فقط — أنشئ .env من .env.example واملأ MySQL + APP_URL ثم:
   cd ~/rpm && bash cpanel-post-deploy.sh --first

5) التحديثات اللاحقة (بعد رفع الملفات عبر CI/FTP):
   cd ~/rpm && bash cpanel-post-deploy.sh

6) Cron كل دقيقة:
   * * * * * cd /home/USER/rpm && php artisan schedule:run >> /dev/null 2>&1

التفعيل الكامل لـ GitHub Actions:
  docs/تفعيل-CI-CD-cPanel.md
EOF

echo "==> تم: dist/cpanel و dist/cpanel-app"
du -sh "$OUT" "$APP_OUT" || true
test -f "$APP_OUT/vendor/autoload.php"
test -f "$APP_OUT/cpanel-post-deploy.sh"
test -f "$OUT/index.php"
test -f "$OUT/.htaccess"
test -f "$OUT/index.html"
echo "==> فحوصات الحزمة نجحت"
