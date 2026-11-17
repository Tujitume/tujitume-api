<?php

namespace App\Modules\ProgramImport\Support;

use Illuminate\Support\Facades\Http;

/** Safe fetch of a public Google Form: host allowlist, max 3 redirects with a host re-check per hop, no private IPs, 10 s, 2 MB. */
class GoogleFormFetcher
{
    public function fetch(string $url): string
    {
        $cfg = config('program_imports.google');
        $max = (int) $cfg['max_redirects'];
        for ($hop = 0; $hop <= $max; $hop++) {
            $this->assertAllowed($url);
            $res = Http::timeout((int) $cfg['timeout'])->withOptions(['allow_redirects' => false, 'stream' => false])->withHeaders(['Accept-Language' => 'en'])->get($url);
            if (in_array($res->status(), [301, 302, 303, 307, 308], true)) {
                $next = (string) $res->header('Location');
                if ($next === '') {
                    throw new FormFetchException('form_unreachable');
                }
                $url = $this->absolute($url, $next);

                continue;
            }
            if (! $res->successful()) {
                throw new FormFetchException($res->status() === 401 || $res->status() === 403 ? 'form_private' : 'form_unreachable');
            }
            if (strlen($res->body()) > (int) $cfg['max_bytes']) {
                throw new FormFetchException('form_too_large');
            }

            return $res->body();
        }
        throw new FormFetchException('too_many_redirects');
    }

    public function assertAllowed(string $url): void
    {
        $p = parse_url(trim($url));
        $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || ! in_array($host, config('program_imports.google.hosts'), true) || isset($p['user']) || isset($p['port'])) {
            throw new FormFetchException('form_url');
        }
        // Resolve and refuse private, loopback and link-local addresses (SSRF), unless a test resolver says the host is public.
        foreach ($this->resolve($host) as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new FormFetchException('form_url');
            }
        }
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        if (app()->bound('program_imports.resolver')) {
            return (array) app('program_imports.resolver')($host);
        }
        $ips = array_column(@dns_get_record($host, DNS_A | DNS_AAAA) ?: [], 'ip') + array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return $ips ?: throw new FormFetchException('form_unreachable');
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);

        return ($p['scheme'] ?? 'https').'://'.$p['host'].(str_starts_with($location, '/') ? '' : '/').$location;
    }
}
