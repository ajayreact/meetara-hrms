<?php

use App\Http\Middleware\EnsureActiveCompany;
use App\Support\CandidateForm;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireProductionHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [
            RequireProductionHttps::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureActiveCompany::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(CandidateForm::sensitiveKeys());
        $exceptions->reportable(function (\Throwable $exception) {
            $request = request();

            if (! $request instanceof Request) {
                return;
            }

            $keys = CandidateForm::sensitiveKeys();
            $request->replace($request->except($keys));

            foreach ($keys as $key) {
                $request->files->remove($key);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            $path = $request->getPathInfo();

            if (! $request->isMethod('POST') || ! preg_match('#^/apply/[a-f0-9]{64}$#', $path)) {
                return null;
            }

            return redirect()->to($path)->withErrors([
                'form' => 'Too many submissions were sent from this network. Wait a minute and try again.',
            ])->withHeaders([
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'private, no-store',
                'Retry-After' => '60',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $path = $request->getPathInfo();

            if (! $request->isMethod('POST') || ! preg_match('#^/apply/[a-f0-9]{64}$#', $path)) {
                return null;
            }

            return redirect()->to($path.'?notice=request-too-large')->withHeaders([
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    })->create();
