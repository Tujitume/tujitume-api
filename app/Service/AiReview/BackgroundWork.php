<?php

namespace App\Service\AiReview;

use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Support\Facades\Cache;

/**
 * Starts `ai-reviews:work` for a round as its own process, for a server that has no queue worker (QUEUE_CONNECTION=sync).
 * That process works through the round's waiting reviews one by one and keeps going after the request that started it has ended,
 * so nobody has to sit and press anything. One process per round; asking again while it runs does nothing.
 */
class BackgroundWork
{
    public static function start(ProgramRound $round): bool
    {
        // Tests never start a real process
        if (app()->runningUnitTests()) {
            return true;
        }
        if (! function_exists('exec')) {
            return false;
        }
        // Many submissions within a couple of seconds ask once, not each
        if (! Cache::add("ai:work:spawned:{$round->id}", 1, 2)) {
            return true;
        }
        $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find(false);
        if (! $php) {
            return false;
        }
        $command = sprintf('%s %s ai-reviews:work %d > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg(base_path('artisan')), $round->id);
        exec($command, $output, $code);

        return $code === 0;
    }

    /** True when there is no queue worker, so the work has to be started as a process of its own. */
    public static function needed(): bool
    {
        return config('queue.default') === 'sync' && ! app()->runningUnitTests();
    }
}
