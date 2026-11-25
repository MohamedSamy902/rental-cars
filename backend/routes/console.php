<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// كل ساعة: يمسح الكل، ويرسل فقط شريحة الساعة الحالية داخل نافذة 13→01
Schedule::command('notifications:scan')
    ->hourly()
    ->withoutOverlapping();

// إثبات إيجار للشهور المفتوحة (idempotent) — الكتابة هنا وليس على GET الحسابات/التقارير
Schedule::command('accounting:sync-rent-recognition')
    ->dailyAt('01:15')
    ->withoutOverlapping();

// توقّف ترحيل استحقاق الملاك اليومي — الصرف التشغيلي = مصروف شهري نقدي مباشرة (owners:purge-accrual-ledger).

