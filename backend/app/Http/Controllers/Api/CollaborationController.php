<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Evaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollaborationController extends Controller
{
    public function chatIndex(Request $request): JsonResponse
    {
        $channel = $request->string('channel', 'ops')->toString();

        return response()->json([
            'data' => ChatMessage::query()
                ->with('user:id,name')
                ->where('channel', $channel)
                ->latest('id')
                ->limit(50)
                ->get()
                ->reverse()
                ->values(),
        ]);
    }

    public function chatStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:1000'],
            'channel' => ['nullable', 'string', 'max:40'],
        ], [], ['body' => 'نص الرسالة']);

        $channel = $data['channel'] ?? 'ops';
        $todayCount = ChatMessage::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('created_at', today())
            ->count();
        if ($todayCount >= 100) {
            return response()->json(['message' => 'تم بلوغ حد الرسائل اليومي (100).'], 422);
        }

        $row = ChatMessage::query()->create([
            'user_id' => $request->user()->id,
            'channel' => $channel,
            'body' => $data['body'],
        ])->load('user:id,name');

        return response()->json(['data' => $row, 'message' => 'تم إرسال الرسالة.'], 201);
    }

    public function evaluationsIndex(): JsonResponse
    {
        return response()->json([
            'data' => Evaluation::query()->with('employee')->latest('id')->limit(100)->get(),
        ]);
    }

    public function evaluationsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'period' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'employee_id' => 'الموظف',
            'score' => 'الدرجة',
        ]);

        $row = Evaluation::query()->create([
            ...$data,
            'evaluator_id' => $request->user()?->id,
        ])->load('employee');

        return response()->json(['data' => $row, 'message' => 'تم حفظ التقييم.'], 201);
    }

    /**
     * مراجعة حقول مستخرجة محلياً — بلا إرسال صور لخدمة خارجية.
     */
    public function ocrReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'in:national_id,license'],
            'raw_text' => ['nullable', 'string', 'max:5000'],
            'hints' => ['nullable', 'array'],
            'hints.full_name' => ['nullable', 'string'],
            'hints.national_id' => ['nullable', 'string'],
            'hints.license_number' => ['nullable', 'string'],
            'hints.birth_date' => ['nullable', 'date'],
            'hints.license_expires_on' => ['nullable', 'date'],
        ], [], ['source' => 'مصدر المستند']);

        $hints = $data['hints'] ?? [];
        $raw = (string) ($data['raw_text'] ?? '');
        $suggested = [
            'full_name' => $hints['full_name'] ?? $this->guessName($raw),
            'national_id' => $hints['national_id'] ?? $this->guessDigits($raw, 14),
            'license_number' => $hints['license_number'] ?? $this->guessLicense($raw),
            'birth_date' => $hints['birth_date'] ?? null,
            'license_expires_on' => $hints['license_expires_on'] ?? null,
        ];

        return response()->json([
            'data' => [
                'source' => $data['source'],
                'suggested' => $suggested,
                'requires_staff_review' => true,
                'note' => 'اقتراح داخلي فقط — راجع الحقول قبل الحفظ. لا تُرسل الصور لخدمات خارجية.',
            ],
        ]);
    }

    private function guessName(string $raw): ?string
    {
        if (preg_match('/[\x{0600}-\x{06FF}][\x{0600}-\x{06FF}\s]{3,}/u', $raw, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    private function guessDigits(string $raw, int $len): ?string
    {
        if (preg_match('/\b(\d{'.$len.'})\b/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    private function guessLicense(string $raw): ?string
    {
        if (preg_match('/\b([A-Za-z0-9\-]{5,20})\b/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }
}
