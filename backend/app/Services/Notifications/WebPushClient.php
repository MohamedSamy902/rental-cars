<?php

namespace App\Services\Notifications;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\WebPush;

/**
 * Apple Web Push يرفض ترويسة vapid إذا وُجد فراغ قبل المفتاح: «, k=».
 * Chrome يقبلها، وSafari يرد 403 BadJwtToken.
 */
class WebPushClient extends WebPush
{
    protected function getVAPIDHeaders(string $audience, ContentEncoding $contentEncoding, array $vapid): ?array
    {
        $headers = parent::getVAPIDHeaders($audience, $contentEncoding, $vapid);
        if (is_array($headers) && isset($headers['Authorization'])) {
            $headers['Authorization'] = str_replace(', k=', ',k=', $headers['Authorization']);
        }

        return $headers;
    }
}
