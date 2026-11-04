<?php

/**
 * مصدر واحد لحدود وأنواع الملفات — يُستخدم من MediaUploadService والواجهة.
 * يجب أن تبقى الحدود ≤ إعدادات PHP على cPanel (انظر docs/cpanel-php-ini.md).
 */
return [
    'disk' => env('UPLOADS_DISK', 'local'),

    'image' => [
        'max_bytes' => (int) env('UPLOADS_IMAGE_MAX_BYTES', 12 * 1024 * 1024),
        'mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/heic',
            'image/heif',
            'image/heic-sequence',
            'image/heif-sequence',
        ],
        'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'],
    ],

    'video' => [
        'max_bytes' => (int) env('UPLOADS_VIDEO_MAX_BYTES', 50 * 1024 * 1024),
        'mimes' => [
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/3gpp',
            'video/x-m4v',
            'video/mp4v-es',
        ],
        'extensions' => ['mp4', 'webm', 'mov', '3gp', 'm4v'],
    ],

    'document' => [
        'max_bytes' => (int) env('UPLOADS_DOCUMENT_MAX_BYTES', 12 * 1024 * 1024),
        'mimes' => [
            'application/pdf',
        ],
        'extensions' => ['pdf'],
    ],

    /**
     * قيم PHP المطلوبة على الاستضافة (توثيق + تحقق اختياري).
     * post_max_size يجب أن يكون أكبر من أكبر ملف + هامش multipart.
     */
    'php_requirements' => [
        'upload_max_filesize' => '64M',
        'post_max_size' => '72M',
        'max_file_uploads' => 20,
        'max_execution_time' => 120,
        'memory_limit' => '256M',
    ],

    'hint_ar' => 'المسموح: صور (jpg/png/webp/gif/heic) وفيديو (mp4/mov/webm/3gp/m4v) وPDF. الحد الأقصى للصورة 12 ميجا وللفيديو 50 ميجا.',
];
