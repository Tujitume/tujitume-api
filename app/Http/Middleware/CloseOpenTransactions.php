<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ISSUE-27 (e2e test 2026-11-27): many controllers open DB::beginTransaction() and return a 422 from their
 * ValidationException catch without rolling back. In a long-running worker (Octane, queues, tests) that transaction
 * stays open, and the NEXT request's writes are swallowed when it is finally discarded: a reply of 201 for a row that
 * was never saved. A request must never leave a transaction behind, so any it opened and did not close is rolled back
 * here, and logged so the controller can be fixed.
 */
class CloseOpenTransactions
{
    public function handle(Request $request, Closure $next): Response
    {
        $level = DB::transactionLevel();

        try {
            return $next($request);
        } finally {
            $left = DB::transactionLevel() - $level;
            if ($left > 0) {
                Log::warning('A request left a database transaction open; rolled back', [
                    'route' => $request->route()?->getActionName(), 'method' => $request->method(), 'path' => $request->path(), 'levels' => $left,
                ]);
                while (DB::transactionLevel() > $level) {
                    DB::rollBack();
                }
            }
        }
    }
}
