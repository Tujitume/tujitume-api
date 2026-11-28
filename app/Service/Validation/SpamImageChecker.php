<?php
namespace App\Service\Validation;
use CURLFile;
use Illuminate\Support\Facades\Storage;

class SpamImageChecker
{
    protected array $badWords;

    public function __construct()
    {

    }

    public function check($image_path)
    {
        //$image_path = '';
        // Off in tests and wherever it is switched off: nothing is sent and nothing is flagged
        if (! config('services.sightengine.enabled', true)) return [];

        $params = array(
            'media' => new CurlFile($image_path),
            'models' => 'nudity-2.1,weapon,alcohol,recreational_drug,medical,offensive-2.0,scam,text-content,gore-2.0,qr-content,violence',
            'api_user' => (string) config('services.sightengine.api_user'),
            'api_secret' => (string) config('services.sightengine.api_secret'),
        );

        // this example uses cURL
        $ch = curl_init('https://api.sightengine.com/1.0/check.json');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);

        if ($response === false) {
            // Sightengine could not be reached: the upload goes ahead (an outage must not block people) and it is logged
            \Illuminate\Support\Facades\Log::warning('Image moderation unavailable', ['error' => curl_error($ch)]);
            curl_close($ch);

            return [];
        }
        curl_close($ch);

        $response = json_decode($response, true);
        if (! is_array($response) || ($response['status'] ?? null) === 'failure') {
            \Illuminate\Support\Facades\Log::warning('Image moderation failed', ['error' => $response['error']['message'] ?? 'unreadable reply']);

            return [];
        }

        $parameters = (array) $this->getNSFWFlags($response);
        return $parameters;
        //return true; // clean
    }

    /** Flags that make an image unacceptable (the same list business and service images are refused for) */
    public const REFUSED = ['violence', 'gore', 'offensive', 'recreational_drug', 'weapon', 'nudity'];

    /** A message refusing this uploaded image, or null when it is fine (or not an image, or moderation is off) */
    public function refusal(\Illuminate\Http\UploadedFile $file): ?string
    {
        if (! str_starts_with(strtolower((string) $file->getMimeType()), 'image/')) return null;

        $flags = $this->check($file->getRealPath());

        return array_intersect(self::REFUSED, $flags)
            ? '"'.($file->getClientOriginalName() ?: 'The image').'" was refused: it looks inappropriate. Upload a different image.'
            : null;
    }

    function getNSFWFlags(array $response, float $threshold = 0.4): array {
        $flags = [];

        $nudity = $response['nudity'] ?? [];
        $maxNudity = max(
            $nudity['mildly_suggestive'] ?? 0,
            $nudity['suggestive'] ?? 0,
            $nudity['very_suggestive'] ?? 0,
            $nudity['erotica'] ?? 0
        );

        if($maxNudity >= $threshold) {
            $flags[] = 'nudity';//  = $maxNudity;
        }


        // 1. Gore
        if (isset($response['gore'])) {
            $goreProb = $response['gore']['prob'] ?? 0;
            if ($goreProb >= $threshold) {
                //$flags['gore'] = $goreProb;
                $flags[] = 'gore';
            }

        }

        // 2. Violence
        if (isset($response['violence'])) {
            $violenceProb = $response['violence']['prob'] ?? 0;
            if ($violenceProb >= $threshold) {
                //$flags['violence'] = $violenceProb;
                $flags[] = 'violence';
            }
        }

        // 3. Offensive
        if (isset($response['offensive'])) {
            foreach ($response['offensive'] as $class => $value) {
                if ($value >= $threshold) {
                    //$flags["offensive.$class"] = $value;
                    $flags[] = 'offensive';
                }
            }
        }

        // 4. Weapon
        if (isset($response['weapon'])) {
            if (isset($response['weapon']['prob']) && $response['weapon']['prob'] >= $threshold) {
                //$flags['weapon'] = $response['weapon']['prob'];
                $flags[] = 'weapon';
            }

        }

        // 5. Alcohol / Recreational drug
        if (isset($response['recreational_drug'])) {
            $drugProb = $response['recreational_drug']['prob'] ?? 0;
            if ($drugProb >= $threshold) {
                //$flags['recreational_drug'] = $drugProb;
                $flags[] = 'recreational_drug';
            }
        }

        return $flags;
    }
}
