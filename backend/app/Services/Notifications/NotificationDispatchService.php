<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;

class NotificationDispatchService
{
    /** سبب آخر فشل إرسال: no_devices | vapid_missing | delivery_rejected | delivery_failed */
    public ?string $lastPushError = null;

    /**
     * يسجّل إشعاراً داخل النظام ويرسل Web Push لكل أجهزة المستخدم إن وُجدت.
     */
    public function notify(
        User $user,
        string $title,
        string $body,
        ?string $linkType = null,
        ?int $linkId = null,
    ): Notification {
        $row = Notification::query()->create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'link_type' => $linkType,
            'link_id' => $linkId,
        ]);

        $this->pushToUser($user, $title, $body, $this->pathFor($linkType, $linkId), $row->id);

        return $row;
    }

    public function pushToUser(User $user, string $title, string $body, string $path = '/notifications', ?int $notificationId = null): int
    {
        $this->lastPushError = null;
        $subs = PushSubscription::query()->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            $this->lastPushError = 'no_devices';

            return 0;
        }

        $webPush = $this->client();
        if (! $webPush) {
            $this->lastPushError = 'vapid_missing';

            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $path,
            'notification_id' => $notificationId,
            'dir' => 'rtl',
            'lang' => 'ar',
        ], JSON_UNESCAPED_UNICODE);

        $sent = 0;
        $lastStatus = null;
        foreach ($subs as $sub) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'publicKey' => $sub->public_key,
                        'authToken' => $sub->auth_token,
                        'contentEncoding' => $this->encodingFor($sub),
                    ]),
                    $payload ?: '{}',
                );
            } catch (\Throwable $e) {
                Log::warning('push.queue_failed', ['subscription_id' => $sub->id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            if ($report->isSuccess()) {
                $sent++;
                PushSubscription::query()->where('endpoint', $endpoint)->update([
                    'last_used_at' => now(),
                    'content_encoding' => 'aes128gcm',
                ]);

                continue;
            }

            $code = $report->getResponse()?->getStatusCode();
            $lastStatus = $code;
            Log::warning('push.delivery_failed', [
                'status' => $code,
                'reason' => $report->getReason(),
            ]);
            // اشتراك منتهٍ أو غير صالح
            if (in_array($code, [404, 410], true)) {
                PushSubscription::query()->where('endpoint', $endpoint)->delete();
            }
        }

        if ($sent < 1) {
            $this->lastPushError = $lastStatus ? 'delivery_rejected' : 'delivery_failed';
        }

        return $sent;
    }

    public function vapidPublicKey(): ?string
    {
        $key = config('services.webpush.vapid.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * المتصفحات الحالية (Chrome / Firefox / Safari) لا تعرض الحمولة إلا بترميز aes128gcm.
     */
    private function encodingFor(PushSubscription $sub): string
    {
        $endpoint = $sub->endpoint;
        $modern = str_contains($endpoint, 'fcm.googleapis.com')
            || str_contains($endpoint, 'mozilla.com')
            || str_contains($endpoint, 'windows.com')
            || str_contains($endpoint, 'push.apple.com');

        if ($modern || $sub->content_encoding !== 'aesgcm') {
            return 'aes128gcm';
        }

        return 'aesgcm';
    }

    private function client(): ?WebPushClient
    {
        $public = config('services.webpush.vapid.public_key');
        $private = config('services.webpush.vapid.private_key');
        $subject = config('services.webpush.vapid.subject', 'mailto:admin@rpm.local');

        if (! is_string($public) || $public === '' || ! is_string($private) || $private === '') {
            Log::info('push.vapid_missing');

            return null;
        }

        return new WebPushClient([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $public,
                'privateKey' => $private,
            ],
        ]);
    }

    private function pathFor(?string $linkType, ?int $linkId): string
    {
        return match ($linkType) {
            'contract' => $linkId ? '/contracts/'.$linkId : '/bookings',
            'vehicle' => $linkId ? '/vehicles/'.$linkId : '/vehicles',
            'customer' => $linkId ? '/customers/'.$linkId : '/customers',
            'owner' => $linkId ? '/owners/'.$linkId : '/owners',
            'payroll' => '/hr',
            'employee' => $linkId ? '/employees/'.$linkId : '/hr',
            'violation' => '/violations',
            'inventory' => '/inventory',
            'waitlist' => '/waitlist',
            'maintenance' => '/maintenance',
            'expense' => '/expenses',
            default => '/notifications',
        };
    }
}
