<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * يمنع تكرار الطلبات المعدِّلة عبر مفتاح Idempotency-Key.
 * الطلبات المتزامنة بنفس المفتاح تنتظر النتيجة الأولى وتُعاد كما هي.
 */
class EnsureIdempotency
{
    private const TTL_HOURS = 24;

    private const LOCK_SECONDS = 60;

    private const WAIT_SECONDS = 45;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 128) {
            return $next($request);
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $method = $request->method();
        $path = '/'.$request->path();
        $hash = $this->requestHash($request);
        $lockName = 'idempotency:'.sha1($userId.'|'.$key);

        $lock = Cache::lock($lockName, self::LOCK_SECONDS);

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return response()->json([
                'message' => 'الطلب قيد المعالجة بمفتاح التكرار نفسه. حاول مرة أخرى بعد لحظات.',
            ], 409);
        }

        try {
            $existing = IdempotencyKey::query()
                ->where('key', $key)
                ->where('user_id', $userId)
                ->first();

            if ($existing) {
                if ($existing->expires_at && $existing->expires_at->isPast()) {
                    $existing->delete();
                    $existing = null;
                } elseif ($existing->request_hash !== $hash
                    || strtoupper($existing->method) !== $method
                    || $existing->path !== $path) {
                    return response()->json([
                        'message' => 'مفتاح التكرار مستخدم لطلب مختلف. أرسل مفتاحاً جديداً.',
                    ], 422);
                } elseif ($existing->status === 'completed' && $existing->response_body !== null) {
                    return $this->replay($existing);
                }
            }

            if (! $existing) {
                $existing = IdempotencyKey::query()->create([
                    'key' => $key,
                    'user_id' => $userId,
                    'method' => $method,
                    'path' => $path,
                    'request_hash' => $hash,
                    'status' => 'processing',
                    'expires_at' => now()->addHours(self::TTL_HOURS),
                ]);
            }

            /** @var Response $response */
            $response = $next($request);

            $body = $response->getContent();
            if ($body !== false && strlen($body) <= 1_000_000) {
                $existing->update([
                    'status' => 'completed',
                    'response_code' => $response->getStatusCode(),
                    'response_body' => $body,
                    'expires_at' => now()->addHours(self::TTL_HOURS),
                ]);
            }

            $response->headers->set('Idempotency-Key', $key);
            $response->headers->set('Idempotency-Replayed', 'false');

            return $response;
        } finally {
            optional($lock)->release();
        }
    }

    private function requestHash(Request $request): string
    {
        $payload = $request->getContent();
        if ($payload === '' && $request->request->count() > 0) {
            $payload = json_encode($request->request->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return hash('sha256', $request->method().'|'.$request->path().'|'.$payload);
    }

    private function replay(IdempotencyKey $row): Response
    {
        $response = response($row->response_body, (int) $row->response_code)
            ->header('Content-Type', 'application/json')
            ->header('Idempotency-Key', $row->key)
            ->header('Idempotency-Replayed', 'true');

        return $response;
    }
}
