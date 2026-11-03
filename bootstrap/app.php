<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (AiException|RequestException|ConnectionException $exception, Request $request) => $request->is('chat/*') && $request->expectsJson()
            ? response()->json(['message' => match (true) {
                $exception instanceof RateLimitedException, $exception instanceof ProviderOverloadedException => "She's getting a lot of messages right now. Try again in a moment.",
                $exception instanceof InsufficientCreditsException => 'Your Gemini account is out of credit.',
                $exception instanceof ProviderConnectionException, $exception instanceof ConnectionException => "Couldn't reach Gemini. Check your internet connection.",
                $exception instanceof RequestException && in_array($exception->response->status(), [400, 401, 403]) => 'Gemini rejected the request. Make sure GEMINI_API_KEY is set to a valid key.',
                default => "She couldn't reply just now. Try again in a moment.",
            }], 503)
            : null);
    })->create();
