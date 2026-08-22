<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        // Only for websockets auth endpoint
        if ($request->is('api/laravel-websockets/*')) {
            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'stack' => $e->getTrace()
            ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
        }

        // Handle model not found exceptions
        if ($e instanceof ModelNotFoundException ||
            $e instanceof NotFoundHttpException) {

            return response()->json([
                'message' => 'Resource not found.'
            ], 404);
        }

        return parent::render($request, $e);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return parent::unauthenticated($request, $exception);
    }
}
