<?php

use App\Domain\Shared\Exception\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Toute violation d'invariant métier (value objects du domaine,
        // contraintes physiques des actifs...) qui atteindrait ce point
        // n'a pas été rattrapée par la validation Http (SimulateRequest) —
        // c'est un signe que la requête était syntaxiquement valide mais
        // sémantiquement incohérente (ex. socMin >= socMax). On la traduit
        // en 422 plutôt que de laisser remonter un 500.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return new JsonResponse(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
