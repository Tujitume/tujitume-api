<?php
namespace App\Service\Misc;

use App\Models\Shared\ErrorLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ErrorLogService
{
    public static function report(\Throwable $e, array $context = [])
    {
        try {
            // Log file (optional fallback)
            Log::error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => Auth::id(),
                'context' => $context,
            ]);

            // Save to database
            ErrorLog::create([
                'user_id' => Auth::id(),
                'type'    => class_basename($e),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'url'     => request()->fullUrl(),
                'context' => json_encode($context),
            ]);

        } catch (\Throwable $inner) {
            // Avoid recursive failures
            Log::critical('ErrorService failed: ' . $inner->getMessage());
        }
    }
}
