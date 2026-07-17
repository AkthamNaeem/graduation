<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureCompanyApproved;
use App\Http\Middleware\EnsureUserIsActive;
use App\Exceptions\RecruitmentAccessException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'company.approved' => EnsureCompanyApproved::class,
            'user.active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RecruitmentAccessException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                errors: $exception->errors,
                status: $exception->status,
                code: $exception->errorCode,
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $exception->errors();

            return ApiResponse::error(
                message: array_key_exists('unanswered_question_ids', $errors)
                    ? 'Some required questions have not been answered.'
                    : 'The given data was invalid.',
                errors: $errors,
                status: 422,
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'Unauthenticated.',
                status: 401,
            );
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'This action is unauthorized.',
                status: 403,
            );
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'This action is unauthorized.',
                status: 403,
            );
        });

        $exceptions->render(function (ConflictHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'The requested operation conflicts with the current resource state.',
                status: 409,
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The requested resource could not be found.',
                status: 404,
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = config('app.debug')
                ? ['exception' => [$exception->getMessage()]]
                : [];

            return ApiResponse::error(
                message: 'An unexpected server error occurred.',
                errors: $errors,
                status: 500,
            );
        });
    })->create();
