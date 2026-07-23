<?php

declare(strict_types=1);

use App\Exceptions\FlashSaleException;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (FlashSaleException $e) => ApiResponse::error($e->errorMessage(), [], $e->httpStatus())
        );

        // Validation
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return ApiResponse::error('Validation failed.', $e->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return null;
        });

        // Unknown route / missing model -> 404 envelope.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return ApiResponse::error('Resource not found.', [], Response::HTTP_NOT_FOUND);
            }

            return null;
        });

        // Wrong HTTP verb -> 405 envelope.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return ApiResponse::error('Method not allowed.', [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            return null;
        });
    })->create();
