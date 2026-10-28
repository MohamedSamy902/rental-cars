<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function __construct(private readonly NotificationDispatchService $dispatch) {}

    public function vapidPublicKey(): JsonResponse
    {
        $key = $this->dispatch->vapidPublicKey();
        if (! $key) {
            return response()->json([
                'enabled' => false,
                'message' => 'إشعارات الدفع غير مفعّلة — أضف مفاتيح VAPID في .env',
            ]);
        }

        return response()->json([
            'enabled' => true,
            'publicKey' => $key,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        if (! $this->dispatch->vapidPublicKey()) {
            return response()->json(['message' => 'إشعارات الدفع غير مفعّلة على السيرفر.'], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ], [], [
            'endpoint' => 'عنوان الاشتراك',
            'keys.p256dh' => 'مفتاح الجهاز',
            'keys.auth' => 'رمز المصادقة',
        ]);

        $row = PushSubscription::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
            ],
            [
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'data' => ['id' => $row->id],
            'message' => 'تم تفعيل إشعارات الموبايل على هذا الجهاز.',
        ], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['message' => 'تم إلغاء اشتراك هذا الجهاز.']);
    }

    public function status(Request $request): JsonResponse
    {
        $count = PushSubscription::query()->where('user_id', $request->user()->id)->count();

        return response()->json([
            'enabled' => (bool) $this->dispatch->vapidPublicKey(),
            'devices' => $count,
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $sent = $this->dispatch->pushToUser(
            $user,
            'اختبار إشعار آر بي إم',
            'لو وصلك الإشعار على الموبايل فالتفعيل ناجح.',
            '/notifications',
        );

        if ($sent < 1) {
            return response()->json([
                'message' => 'لا يوجد جهاز مشترك أو مفاتيح VAPID غير مضبوطة. فعّل الإشعارات من الصفحة أولاً.',
            ], 422);
        }

        return response()->json([
            'message' => "تم إرسال إشعار تجريبي إلى {$sent} جهاز.",
            'sent' => $sent,
        ]);
    }
}
