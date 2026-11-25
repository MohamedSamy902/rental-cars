<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * OPS-1: قياس معزول لمسارات حرجة (كويري · زمن · بايت).
 */
class PerfMeasureCommand extends Command
{
    protected $signature = 'perf:measure
        {--user=admin@rpm.local : بريد المستخدم }
        {--json : خرج JSON فقط}';

    protected $description = 'قياس عدد الاستعلامات/الزمن/الحجم لمسارات الأداء الحرجة';

    /** @var list<string> */
    private array $paths = [
        '/api/vehicles?per_page=50',
        '/api/dashboard',
        '/api/contracts?per_page=50',
        '/api/contracts?view=calendar&per_page=200',
        '/api/attention',
    ];

    public function handle(): int
    {
        $email = (string) $this->option('user');
        $user = User::query()->where('email', $email)->first()
            ?? User::query()->orderBy('id')->first();

        if ($user === null) {
            $this->error('لا يوجد مستخدم للقياس.');

            return self::FAILURE;
        }

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $rows = [];

        foreach ($this->paths as $path) {
            Sanctum::actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $t0 = microtime(true);
            $req = Request::create($path, 'GET');
            $req->headers->set('Accept', 'application/json');
            $res = $kernel->handle($req);
            $ms = (microtime(true) - $t0) * 1000;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $kernel->terminate($req, $res);

            $rows[] = [
                'path' => $path,
                'status' => $res->getStatusCode(),
                'queries' => $queries,
                'ms' => round($ms, 1),
                'bytes' => strlen($res->getContent()),
            ];
        }

        $payload = [
            'measured_at' => now()->toIso8601String(),
            'user' => $user->email,
            'endpoints' => $rows,
            'budgets' => [
                'vehicles_queries_max' => 25,
                'dashboard_queries_max' => 30,
                'contracts_list_queries_max' => 25,
                'calendar_bytes_max' => 100_000,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['path', 'status', 'queries', 'ms', 'bytes'], array_map(
            fn ($r) => [$r['path'], $r['status'], $r['queries'], $r['ms'], $r['bytes']],
            $rows
        ));

        return self::SUCCESS;
    }
}
