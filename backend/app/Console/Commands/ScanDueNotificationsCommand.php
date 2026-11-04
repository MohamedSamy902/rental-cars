<?php

namespace App\Console\Commands;

use App\Services\Notifications\DueNotificationService;
use Illuminate\Console\Command;

class ScanDueNotificationsCommand extends Command
{
    protected $signature = 'notifications:scan';

    protected $description = 'مسح الحجوزات والماليات والتنبيهات وإرسال الإشعار الأول ثم تذكير كل 24 ساعة';

    public function handle(DueNotificationService $notifications): int
    {
        $result = $notifications->scan();
        $this->info("تنبيهات أُرسلت: {$result['alerts']} · صفوف إشعار: {$result['notifications']}");

        return self::SUCCESS;
    }
}
