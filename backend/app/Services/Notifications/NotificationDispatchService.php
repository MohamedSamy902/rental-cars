<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotificationDispatchService
{
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
        $subs = PushSubscription::query()->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        $webPush = $this->client();
        if (! $webPush) {
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
        foreach ($subs as $sub) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'publicKey' => $sub->public_key,
                        'authToken' => $sub->auth_token,
                        'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
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
                PushSubscription::query()->where('endpoint', $endpoint)->update(['last_used_at' => now()]);

                continue;
            }

            $code = $report->getResponse()?->getStatusCode();
            // اشتراك منتهٍ أو غير صالح
            if (in_array($code, [404, 410], true)) {
                PushSubscription::query()->where('endpoint', $endpoint)->delete();
            } else {
                Log::warning('push.delivery_failed', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                    'status' => $code,
                ]);
            }
        }

        return $sent;
    }

    public function vapidPublicKey(): ?string
    {
        $key = config('services.webpush.vapid.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function client(): ?WebPush
    {
        $public = config('services.webpush.vapid.public_key');
        $private = config('services.webpush.vapid.private_key');
        $subject = config('services.webpush.vapid.subject', 'mailto:admin@rpm.local');

        if (! is_string($public) || $public === '' || ! is_string($private) || $private === '') {
            Log::info('push.vapid_missing');

            return null;
        }

        return new WebPush([
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
            'customer' => '/customers',
            'owner' => '/owners',
            'payroll' => '/hr',
            default => '/notifications',
        };
    }
}
