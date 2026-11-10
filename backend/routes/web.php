<?php

/*
| لا مسارات Blade للتشغيل اليومي.
| الواجهة = SPA في public_html/index.html · الـ API = routes/api.php عبر index.php
*/

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/index.html');
