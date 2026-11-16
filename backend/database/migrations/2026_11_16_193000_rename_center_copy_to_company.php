<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد التسمية الظاهرة: «مركز» → «شركة» في الإعدادات وقوالب المستندات المخزّنة.
 * مفتاح الحقل center_name يبقى كما هو للتوافق مع الـ API.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_settings')) {
            foreach (DB::table('tenant_settings')->select(['id', 'center_name', 'print_footer'])->get() as $row) {
                $name = $this->rewrite((string) ($row->center_name ?? ''));
                $footer = $this->rewrite((string) ($row->print_footer ?? ''));
                if ($name === (string) ($row->center_name ?? '') && $footer === (string) ($row->print_footer ?? '')) {
                    continue;
                }
                DB::table('tenant_settings')->where('id', $row->id)->update([
                    'center_name' => $name !== '' ? $name : $row->center_name,
                    'print_footer' => $footer,
                ]);
            }
        }

        if (Schema::hasTable('document_templates')) {
            foreach (DB::table('document_templates')->select(['id', 'title', 'content'])->get() as $row) {
                $title = $this->rewrite((string) ($row->title ?? ''));
                $content = $this->rewrite((string) ($row->content ?? ''));
                if ($title === (string) ($row->title ?? '') && $content === (string) ($row->content ?? '')) {
                    continue;
                }
                DB::table('document_templates')->where('id', $row->id)->update([
                    'title' => $title,
                    'content' => $content,
                ]);
            }
        }
    }

    public function down(): void
    {
        // لا نعيد «مركز» تلقائياً — التسمية الجديدة مقصودة.
    }

    private function rewrite(string $text): string
    {
        if ($text === '' || ! str_contains($text, 'مركز')) {
            return $text;
        }

        // لا تلمس «متمركز» ولا «مركز الإشعارات».
        $text = str_replace(['متمركز', 'متمركزة', 'مركز الإشعارات'], ["\0A\0", "\0B\0", "\0C\0"], $text);
        $map = [
            'مركز آر بي إم للتأجير' => 'شركة آر بي إم للتأجير',
            'مركز آر بي إم' => 'شركة آر بي إم',
            'مركز التأجير' => 'شركة التأجير',
            'ملك المركز' => 'ملك الشركة',
            'اسم المركز' => 'اسم الشركة',
            'هوية المركز' => 'هوية الشركة',
            'إعدادات المركز' => 'إعدادات الشركة',
            'نموذج المركز' => 'نموذج الشركة',
            'ختم المركز' => 'ختم الشركة',
            'أنظمة المركز' => 'أنظمة الشركة',
            'سياسة المركز' => 'سياسة الشركة',
            'لدى المركز' => 'لدى الشركة',
            'للمركز' => 'للشركة',
            'بالمركز' => 'بالشركة',
            'من المركز' => 'من الشركة',
            'عن المركز' => 'عن الشركة',
            'مع المركز' => 'مع الشركة',
            'بين المركز' => 'بين الشركة',
            'مركز التشغيل' => 'شركة التشغيل',
            'المستأجر / المركز' => 'المستأجر / الشركة',
            'مالك ↔ مركز' => 'مالك ↔ شركة',
            'يتولاها الشركة' => 'تتولاها الشركة',
            'بصفته مركزاً' => 'بصفته شركة',
            'مركزاً' => 'شركةً',
            'المركز' => 'الشركة',
            'مركز' => 'شركة',
        ];
        foreach ($map as $from => $to) {
            $text = str_replace($from, $to, $text);
        }

        return str_replace(["\0A\0", "\0B\0", "\0C\0"], ['متمركز', 'متمركزة', 'مركز الإشعارات'], $text);
    }
};
