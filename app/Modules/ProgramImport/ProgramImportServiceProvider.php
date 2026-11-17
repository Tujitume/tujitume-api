<?php

namespace App\Modules\ProgramImport;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Program Import module. Everything is a no-op unless config('program_imports.enabled') is true,
 * so the platform never depends on this module. To remove the module: delete app/Modules/ProgramImport,
 * routes/api/program-imports.php, config/program_imports.php, the provider line in config/app.php and the tables.
 */
class ProgramImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/program_imports.php'), 'program_imports');
    }

    public function boot(): void
    {
        if (! config('program_imports.enabled')) {
            return;
        }

        Silent\OutboundGuard::flush();
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->loadViewsFrom(__DIR__.'/resources/views', 'program-import');
        \Illuminate\Support\Facades\Gate::policy(Models\ImportBatch::class, Policies\ImportBatchPolicy::class);
        \Illuminate\Support\Facades\RateLimiter::for('program-imports', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('pi:'.($r->user()?->id ?? $r->ip())));
        \Illuminate\Support\Facades\RateLimiter::for('program-imports-ai', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(8)->by('pia:'.($r->user()?->id ?? $r->ip())));
        if ($this->app->runningInConsole()) {
            $this->commands([Console\PurgeImports::class]);
            $this->app->booted(fn () => $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->command('program-imports:purge')->daily()->onOneServer());
        }

        // Google redirects the browser here without a login; the signed state identifies the organization.
        \Illuminate\Support\Facades\Route::middleware(['api', 'throttle:20,1'])->get('api/v1/program-imports/integrations/google/callback', [Http\Controllers\GoogleController::class, 'callback']);

        if (is_file(base_path('routes/api/program-imports.php'))) {
            \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'extend-token', 'throttle:api'])
                ->prefix('api/v1/program-imports')
                ->group(base_path('routes/api/program-imports.php'));
        }
    }
}
