<?php

use App\Services\Documents\DocumentTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * ترقية لمرة واحدة للقوالب الافتراضية القديمة (بلا updated_by).
 * بعد هذا لا تُعاد الترقية من مسار القراءة حتى لا تُمحى تعديلات الإعدادات.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_templates')) {
            return;
        }

        app(DocumentTemplateService::class)->upgradeStockTemplatesIfNeeded();
    }

    public function down(): void
    {
        // لا تراجع — الإصدارات السابقة تبقى في الجدول.
    }
};
