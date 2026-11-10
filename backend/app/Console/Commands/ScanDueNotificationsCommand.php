<?php

namespace App\Console\Commands;

use App\Services\Notifications\DueNotificationService;
use Illuminate\Console\Command;

class ScanDueNotificationsCommand extends Command
{
    protected $signature = 'notifications:scan';

    protected $description = 'مسح الحجوزات والماليات والتنبيهات؛ إرسال موزّع على نافذة 13→01 ثم تذكير كل 24 ساعة';

    public function handle(DueNotificationService $notifications): int
    {
        $result = $notifications->scan();
        $slot = $notifications->currentSpreadSlot();
        $window = $notifications->spreadEnabled()
            ? ($slot === null
                ? 'خارج نافذة الإرسال (13→01)'
                : 'شريحة '.($slot + 1).'/'.$notifications->spreadHours())
            : 'توزيع معطّل';
        $this->info("تنبيهات أُرسلت: {$result['alerts']} · صفوف إشعار: {$result['notifications']} · {$window}");

        return self::SUCCESS;
    }
}
