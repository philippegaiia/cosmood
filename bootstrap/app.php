<?php

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $renderForbiddenAdminRedirect = function (Request $request) {
            if (
                (! $request->isMethod('GET')) ||
                $request->expectsJson() ||
                (! $request->routeIs('filament.admin.*'))
            ) {
                return null;
            }

            Notification::make()
                ->danger()
                ->title(__('Accès refusé'))
                ->body(__('Vous n’avez pas l’autorisation d’accéder à cet écran ou d’exécuter cette action.'))
                ->send();

            return new RedirectResponse(Filament::getHomeUrl());
        };

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($renderForbiddenAdminRedirect) {
            return $renderForbiddenAdminRedirect($request);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($renderForbiddenAdminRedirect) {
            if ($exception->getStatusCode() !== 403) {
                return null;
            }

            return $renderForbiddenAdminRedirect($request);
        });
    })->create();
