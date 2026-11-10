<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    /*
     | أصول المتصفح المسموحة (مفصولة بفواصل في .env).
     | مع Vercel Rewrite لـ /api غالباً لا تحتاج CORS (نفس الأصل).
     | عند VITE_API_ORIGIN مباشر من دومين Vercel → أضف رابط Vercel هنا.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://127.0.0.1:3847,http://localhost:3847',
        )),
    ))),
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', '')),
    ))),
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
