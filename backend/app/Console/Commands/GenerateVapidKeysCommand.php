<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'push:vapid {--write : كتابة المفاتيح في ملف .env}';

    protected $description = 'توليد مفاتيح VAPID لإشعارات Web Push على الموبايل';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $public = $keys['publicKey'];
        $private = $keys['privateKey'];

        $this->info('VAPID_PUBLIC_KEY='.$public);
        $this->info('VAPID_PRIVATE_KEY='.$private);
        $this->line('VAPID_SUBJECT=mailto:admin@your-domain.com');

        if ($this->option('write')) {
            $envPath = base_path('.env');
            if (! is_file($envPath)) {
                $this->error('ملف .env غير موجود.');

                return self::FAILURE;
            }
            $env = file_get_contents($envPath) ?: '';
            $env = $this->upsertEnv($env, 'VAPID_PUBLIC_KEY', $public);
            $env = $this->upsertEnv($env, 'VAPID_PRIVATE_KEY', $private);
            if (! str_contains($env, 'VAPID_SUBJECT=')) {
                $env = rtrim($env)."\nVAPID_SUBJECT=mailto:admin@rpm.local\n";
            }
            file_put_contents($envPath, $env);
            $this->info('تم تحديث .env — أعد تحميل الإعدادات إن لزم (config:clear).');
        }

        return self::SUCCESS;
    }

    private function upsertEnv(string $env, string $key, string $value): string
    {
        $line = $key.'='.$value;
        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
            return preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env) ?? $env;
        }

        return rtrim($env)."\n{$line}\n";
    }
}
