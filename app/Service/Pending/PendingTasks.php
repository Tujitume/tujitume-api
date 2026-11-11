<?php

namespace App\Service\Pending;

use App\Models\Auth\User;
use Illuminate\Support\Facades\Log;

/**
 * Everything that is waiting for the signed-in person to act, found in ONE pass and grouped for the Pending drawer.
 *
 * The work is done by scenario classes (see Scenario): every class under Scenarios/ is discovered automatically, so a
 * new feature adds a file and appears in the drawer, with no other change. A scenario that fails is logged and skipped;
 * the others still return.
 */
class PendingTasks
{
    /** @var Scenario[]|null */
    private static ?array $registry = null;

    /** @return Scenario[] every scenario, sorted by key */
    public static function registry(): array
    {
        if (self::$registry !== null) return self::$registry;

        $found = [];
        $base = __DIR__.'/Scenarios';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;
            $relative = str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($base) + 1));
            $class = __NAMESPACE__.'\\Scenarios\\'.$relative;
            if (! class_exists($class) || ! is_subclass_of($class, Scenario::class)) continue;
            if ((new \ReflectionClass($class))->isAbstract()) continue;
            $scenario = new $class();
            $found[$scenario->key()] = $scenario;
        }
        ksort($found);

        return self::$registry = $found;
    }

    public function for(User $user): array
    {
        $type = (int) $user->user_type_id;

        $groups = [];
        foreach (self::registry() as $key => $scenario) {
            if (! $scenario->appliesTo($type)) continue;
            try {
                $found = $scenario->forUser($user)->collect();
            } catch (\Throwable $e) {
                Log::warning('Pending scenario failed', ['scenario' => $key, 'class' => get_class($e), 'message' => $e->getMessage()]);
                continue;
            }
            foreach ($found as $group) {
                if ($group['count'] < 1) continue;
                $groups[] = $group;
            }
        }

        $total = array_sum(array_column($groups, 'count'));
        $byGroup = [];
        foreach ($groups as $g) {
            $byGroup[$g['group']]['key'] = $g['group'];
            $byGroup[$g['group']]['count'] = ($byGroup[$g['group']]['count'] ?? 0) + $g['count'];
            $byGroup[$g['group']]['items'] = array_slice(array_merge($byGroup[$g['group']]['items'] ?? [], $g['items']), 0, 40);
        }

        return ['total' => $total, 'groups' => array_values($byGroup), 'generated_at' => now()->toIso8601String()];
    }
}
