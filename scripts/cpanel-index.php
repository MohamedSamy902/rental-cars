<?php

/**
 * نقطة دخول cPanel — ضع هذا الملف كـ public_html/index.php
 * وعدّل المسار $laravelRoot ليشير لمجلد تطبيق Laravel خارج الويب.
 *
 * مثال شائع:
 *   public_html/     ← هذا الملف + أصول SPA
 *   rpm/             ← كود Laravel (artisan, app, vendor, ...)
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// ← غيّر هذا المسار حسب رفعك على الاستضافة
$laravelRoot = __DIR__.'/../rpm';

if (! file_exists($laravelRoot.'/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "تعذّر العثور على Laravel في: {$laravelRoot}\n";
    echo "عدّل المتغير \$laravelRoot في public_html/index.php\n";
    exit(1);
}

require $laravelRoot.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
