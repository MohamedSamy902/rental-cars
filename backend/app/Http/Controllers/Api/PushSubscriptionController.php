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
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
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
        if (! $this->dispatch->vapidPublicKey()) {
            return response()->json([
                'message' => 'مفاتيح VAPID غير مضبوطة على السيرفر.',
            ], 422);
        }

        $user = $request->user();
        $sent = $this->dispatch->pushToUser(
            $user,
            'اختبار إشعار آر بي إم',
            'لو وصلك الإشعار على الموبايل فالتفعيل ناجح.',
            '/notifications',
        );

        if ($sent < 1) {
            $message = match ($this->dispatch->lastPushError) {
                'vapid_missing' => 'مفاتيح VAPID غير مضبوطة على السيرفر.',
                'delivery_rejected' => 'الجهاز المسجّل لم يعد صالحاً. اضغط «تفعيل على هذا الجهاز» مرة أخرى واسمح بالإشعارات.',
                'delivery_failed' => 'تعذّر تسليم الإشعار للجهاز المسجّل. أعد التفعيل من هذه الصفحة.',
                default => 'لا يوجد جهاز مشترك. اضغط «تفعيل على هذا الجهاز» واسمح للمتصفح بالإشعارات.',
            };

            return response()->json(['message' => $message], 422);
        }

        return response()->json([
            'message' => "تم إرسال إشعار تجريبي إلى {$sent} جهاز.",
            'sent' => $sent,
        ]);
    }
}
