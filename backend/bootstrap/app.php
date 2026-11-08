<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureFeatureFlag;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureIntegrator;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'feature' => EnsureFeatureFlag::class,
            'integrator' => EnsureIntegrator::class,
            'idempotency' => EnsureIdempotency::class,
            'active' => EnsureActiveUser::class,
        ]);
        $middleware->api(append: [
            EnsureIdempotency::class,
        ]);
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsurePermission::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureFeatureFlag::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureIntegrator::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $payload = ['message' => __('http.payload_too_large')];
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($payload, 413);
            }

            return response($payload['message'], 413)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            $payload = ['message' => __('http.page_expired')];
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($payload, 419);
            }

            return response($payload['message'], 419);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $payload = [
                'message' => __('http.too_many_requests', ['seconds' => $seconds]),
                'retry_after' => $seconds,
            ];
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($payload, 429)->withHeaders($e->getHeaders());
            }

            return response($payload['message'], 429)->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // Always handle API auth failures as JSON 401 — including non-JSON Accept
            // headers that would otherwise attempt a missing /login redirect and 500.
            if ($request->is('api/*') || $request->expectsJson() || $request->is('*/api/*')) {
                return response()->json(['message' => __('http.unauthenticated')], 401);
            }

            return response()->json(['message' => __('http.unauthenticated')], 401);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : __('http.forbidden');
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return response($message, 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => __('http.not_found')], 404);
            }

            return response(__('http.not_found'), 404);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            // Laravel already returns Arabic messages when lang/ar is present;
            // ensure JSON shape is consistent for the SPA.
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'البيانات المدخلة غير صحيحة.',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            return null;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => __('http.unauthenticated')], 401);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if (in_array($status, [401, 403, 404, 419, 413, 429], true)) {
                    return null; // handled above or by framework with our messages
                }
            }

            // Missing named login route during guest redirect must never become a 500 on API.
            if ($e instanceof RouteNotFoundException
                && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json(['message' => __('http.unauthenticated')], 401);
            }

            if (! config('app.debug') && ($request->is('api/*') || $request->expectsJson())) {
                if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                    return null;
                }

                return response()->json(['message' => __('http.server_error')], 500);
            }

            return null;
        });
    })->create();
