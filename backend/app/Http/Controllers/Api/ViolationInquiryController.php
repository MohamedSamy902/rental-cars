<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Violations\ViolationInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ViolationInquiryController extends Controller
{
    public function __construct(private readonly ViolationInquiryService $inquiries) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'pending', 'done_today', 'rented_today'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $canViewNid = (bool) $request->user()?->hasPermission('sensitive.view_national_id');
        $result = $this->inquiries->list(
            $data['filter'] ?? 'pending',
            $data['search'] ?? null,
            $canViewNid,
        );

        return response()->json($result);
    }

    public function mark(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $row = $this->inquiries->mark($vehicle, $user, $data['notes'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $row,
            'message' => ViolationInquiryService::AUTO_DAILY_RESET
                ? 'تم تسجيل الاستعلام لليوم.'
                : 'تم تسجيل الاستعلام.',
        ]);
    }

    public function resetAll(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 401);

        $result = $this->inquiries->resetAll();

        return response()->json([
            'data' => $result,
            'message' => $result['cleared'] > 0
                ? "أُعيد {$result['cleared']} لوحة لبانتظار الاستعلام."
                : 'لا علامات استعلام لإعادتها.',
        ]);
    }
}
