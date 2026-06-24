<?php
namespace App\Service\Validation;
use Illuminate\Support\Facades\Http;

class UrlValidator
{
    public function checkValidity($url)
    {
        // Normalize URL
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'http://' . $url;
        }

        // Check valid URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Parse host
        $host = strtolower(parse_url($url, PHP_URL_HOST));

        // Define allowed domains
        $allowedDomains = [
            'youtube.com',
            'www.youtube.com',
            'youtu.be',
            'vimeo.com',
            'www.vimeo.com',
            'dailymotion.com',
            'www.dailymotion.com',
            'twitch.tv',
            'www.twitch.tv',
            'facebook.com',
            'www.facebook.com',
            'metacafe.com',
            'www.metacafe.com',
            'vevo.com',
            'www.vevo.com',
            'crunchyroll.com',
            'www.crunchyroll.com',
            'liveleak.com',
            'www.liveleak.com',
            'bitChute.com',
            'www.bitChute.com',
            'vidyard.com',
            'www.vidyard.com'
        ];


        // Check if host ends with any allowed domain (to allow subdomains)
        $validDomain = false;
        foreach ($allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
                $validDomain = true;
                break;
            }
        }

        if (!$validDomain) {
            return false; // domain not allowed
        }

        // Then do reachability check as before
        try {
            $response = Http::timeout(5)->head($url);
            if ($response->failed()) {
                $response = Http::timeout(5)->get($url);
            }
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

}
