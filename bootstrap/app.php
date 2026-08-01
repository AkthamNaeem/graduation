<?php

use App\Exceptions\ApplicationInformationRequestException;
use App\Exceptions\ApplicationInternalNoteException;
use App\Exceptions\CompanyManagementException;
use App\Exceptions\CVLifecycleException;
use App\Exceptions\EmailVerificationException;
use App\Exceptions\InterviewLifecycleException;
use App\Exceptions\JobPostingOperationException;
use App\Exceptions\PasswordResetOtpException;
use App\Exceptions\PrivateFileStorageException;
use App\Exceptions\RecruitmentAccessException;
use App\Exceptions\TestAttemptTimingException;
use App\Exceptions\TestContentAccessException;
use App\Exceptions\TestScorePolicyException;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuthenticateSanctumOptionally;
use App\Http\Middleware\EnsureCompanyApproved;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetRequestLocale;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\RequestEntityTooLargeHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetRequestLocale::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'auth.sanctum.optional' => AuthenticateSanctumOptionally::class,
            'company.approved' => EnsureCompanyApproved::class,
            'user.active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (CompanyManagementException $exception, Request $request) {
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

        $exceptions->render(function (PasswordResetOtpException $exception, Request $request) {
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

        $exceptions->render(function (EmailVerificationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = ApiResponse::error(
                message: $exception->getMessage(),
                errors: $exception->errors,
                status: $exception->status,
                code: $exception->errorCode,
            );

            if ($exception->responseData === []) {
                return $response;
            }

            return response()->json(
                array_merge($response->getData(true), $exception->responseData),
                $exception->status,
            );
        });

        $exceptions->render(function (PrivateFileStorageException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                status: $exception->status,
                code: $exception->errorCode,
            );
        });
        $exceptions->render(function (ApplicationInternalNoteException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), $exception->errors, $exception->status, $exception->errorCode);
        });
        $exceptions->render(function (CVLifecycleException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = ApiResponse::error($exception->getMessage(), $exception->errors, $exception->status, $exception->errorCode);
            $payload = array_merge($response->getData(true), ['statusCode' => $exception->status]);
            if ($exception->data !== null) {
                $payload['data'] = $exception->data;
            }
            if ($exception->errorCode === 'SUGGESTION_STALE') {
                $payload['suggestion_id'] = $exception->errors['suggestion_id'] ?? null;
                $payload['entity_type'] = $exception->errors['entity_type'] ?? null;
            }

            return response()->json($payload, $exception->status);
        });
        $exceptions->render(function (InterviewLifecycleException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), $exception->errors, $exception->status, $exception->errorCode);
        });

        $exceptions->render(function (TestScorePolicyException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), $exception->errors, $exception->status, $exception->errorCode);
        });

        $exceptions->render(function (TestAttemptTimingException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), $exception->errors, $exception->status, $exception->errorCode);
        });

        $exceptions->render(function (TestContentAccessException $exception, Request $request) {
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

        $exceptions->render(function (ApplicationInformationRequestException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), $exception->errors, $exception->status, $exception->errorCode);
        });
        $exceptions->render(function (JobPostingOperationException $exception, Request $request) {
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
                    ? __('api.required_questions_unanswered')
                    : __('api.validation_failed'),
                errors: $errors,
                status: 422,
                code: array_key_exists('max_score', $errors) ? 'TEST_MAX_SCORE_IS_SYSTEM_MANAGED' : null,
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: __('api.unauthenticated'),
                status: 401,
            );
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: __('api.unauthorized'),
                status: 403,
            );
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: __('api.unauthorized'),
                status: 403,
            );
        });

        $exceptions->render(function (ConflictHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: __('api.conflict'),
                status: 409,
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: __('api.not_found'),
                status: 404,
            );
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error(__('api.method_not_allowed'), status: 405)
                : null;
        });

        $exceptions->render(function (TooManyRequestsHttpException $exception, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error(__('api.rate_limited'), status: 429)
                : null;
        });

        $exceptions->render(function (RequestEntityTooLargeHttpException $exception, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error(__('api.payload_too_large'), status: 413)
                : null;
        });

        $exceptions->render(function (UnsupportedMediaTypeHttpException $exception, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error(__('api.unsupported_media_type'), status: 415)
                : null;
        });

        $exceptions->render(function (HttpResponseException $exception) {
            return $exception->getResponse();
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: __('api.server_error'),
                status: 500,
            );
        });
    })->create();
