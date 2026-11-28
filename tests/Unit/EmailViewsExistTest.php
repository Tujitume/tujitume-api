<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every email template the code sends must exist (a missing one fails silently: the person never gets the email).
 * Scans app/ for EmailService->send(subject, 'view'), Mail::send('view') and ProgramNotificationService email_view.
 */
class EmailViewsExistTest extends TestCase
{
    public function test_every_email_template_named_in_the_code_exists(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app'));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php') || str_contains($path, '/Unused/')) continue;
            // drop comments so switched-off sends are not counted
            $code = preg_replace(['#//[^\n]*#', '#/\*.*?\*/#s'], '', file_get_contents($path));
            preg_match_all("/(?:emailService|EmailService\(\)|\\\$email|\\\$mailer)\s*->\s*send\(\s*(?:\"[^\"]*\"|'[^']*'|[^,()]+(?:\([^()]*\))?)\s*,\s*'([A-Za-z0-9_.\-]+)'/s", $code, $a);
            preg_match_all("/Mail::send\(\s*'([A-Za-z0-9_.\-]+)'/", $code, $b);
            preg_match_all("/view_base\s*\.\s*'([a-z_]+)'/", $code, $c);
            $views = array_merge($a[1], $b[1], array_map(fn ($v) => 'programs.'.$v, $c[1]));
            foreach ($views as $view) {
                if (! is_file($root.'/resources/views/'.str_replace('.', '/', $view).'.blade.php')) {
                    $missing[] = $view.' ('.str_replace($root.'/', '', $path).')';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Email templates missing');
    }
}
