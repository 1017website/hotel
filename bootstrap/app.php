<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\SubscriptionUnavailable $error, \Illuminate\Http\Request $request) {
            if ($error->publicBooking) return response()->view('saas.unavailable', ['message' => $error->getMessage()], $error->getStatusCode())->header('Cache-Control', 'no-store');
            if ($request->expectsJson()) return response()->json(['message' => $error->getMessage(), 'subscription_unavailable' => true, 'state' => $error->state, 'redirect' => '/subscription'], $error->getStatusCode())->header('Cache-Control', 'no-store');
            return redirect('/subscription');
        });
    })->create();
