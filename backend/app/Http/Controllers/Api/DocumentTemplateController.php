<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\Documents\DocumentTemplateService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTemplateController extends Controller
{
    public function __construct(private readonly DocumentTemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $activeOnly = filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN);
        $rows = $activeOnly ? $this->templates->listActive() : $this->templates->listAll();

        return response()->json([
            'data' => array_map(fn (DocumentTemplate $t) => $this->templates->present($t), $rows),
            'placeholders' => $this->templates->placeholderCatalog(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', DocumentTemplate::allowedTypes())],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'format' => ['nullable', 'in:html,markdown,docx'],
            'file' => ['nullable', 'file', 'max:10240'],
        ], [
            'type.required' => 'نوع القالب مطلوب.',
            'type.in' => 'نوع القالب غير صالح.',
        ], [
            'type' => 'نوع القالب',
            'title' => 'عنوان القالب',
            'content' => 'محتوى القالب',
            'format' => 'صيغة القالب',
            'file' => 'ملف القالب',
        ]);

        try {
            $row = $this->templates->publish($data, $request->file('file'), $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->templates->present($row),
            'message' => 'تم حفظ القالب ونشر إصدار جديد.',
        ], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', DocumentTemplate::allowedTypes())],
            'variables' => ['nullable', 'array'],
        ], [], [
            'type' => 'نوع القالب',
        ]);

        try {
            $result = $this->templates->preview(
                $data['type'],
                isset($data['variables']) ? array_map('strval', $data['variables']) : null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'html' => $result['html'],
                'variables' => $result['variables'],
                'template' => $this->templates->present($result['template']),
            ],
        ]);
    }
}
