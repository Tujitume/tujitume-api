<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Lists every frontend route name (dot notation) the API can put in a notification or email link.
 * The frontend checks this list against its route table (npm run test:links), so a typo or a
 * renamed route fails in CI instead of silently sending people to their dashboard home.
 *
 *   php artisan notifications:link-routes            # readable list
 *   php artisan notifications:link-routes --json     # for the frontend check
 */
class ListNotificationLinkRoutes extends Command
{
    protected $signature = 'notifications:link-routes {--json : Print a JSON array}';

    protected $description = 'List the frontend route names used in notification / email links.';

    public function handle(): int
    {
        $names = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') continue;

            preg_match_all("/['\"](dashboard(?:\.[A-Za-z]+)+)(?:::[^'\"]*)?['\"]/", File::get($file->getPathname()), $matches);
            foreach ($matches[1] as $name) $names[$name] = true;
        }

        $names = array_keys($names);
        sort($names);

        if ($this->option('json')) {
            $this->line(json_encode($names, JSON_PRETTY_PRINT));
        } else {
            foreach ($names as $name) $this->line($name);
        }

        return self::SUCCESS;
    }
}
