<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\IntegrationApi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        // Global, so error pages of unmatched routes (404) also get the CSP and a nonce.
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 1C API: every error uses the Spec 02 9.7 envelope; internals never leak.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/integration/*')) {
                return null;
            }

            return match (true) {
                $exception instanceof HttpResponseException => $exception->getResponse(),
                $exception instanceof NotFoundHttpException => IntegrationApi::error($request, 404, 'not_found', 'Not found.'),
                $exception instanceof MethodNotAllowedHttpException => IntegrationApi::error($request, 405, 'method_not_allowed', 'Method not allowed.'),
                $exception instanceof HttpExceptionInterface => IntegrationApi::error($request, $exception->getStatusCode(), 'http_error', 'Request failed.'),
                default => IntegrationApi::error($request, 500, 'internal_error', 'Internal error; retry the same batch_id later.'),
            };
        });
    })->create();
