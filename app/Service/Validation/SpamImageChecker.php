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
        $params = array(
            'media' => new CurlFile($image_path),
            'models' => 'nudity-2.1,weapon,alcohol,recreational_drug,medical,offensive-2.0,scam,text-content,gore-2.0,qr-content,violence',
            'api_user' => '725115685',
            'api_secret' => 'AgH2RUSa3FPoq2HexU9b32XqFvQhtSpa',
        );

        // this example uses cURL
        $ch = curl_init('https://api.sightengine.com/1.0/check.json');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);

        if ($response === false) {
            return array([
                'error' => true,
                'message' => curl_error($ch)
            ]);
        }
        curl_close($ch);

        $response = json_decode($response, true);

        $parameters = (array) $this->getNSFWFlags($response);
        return $parameters;
        //return true; // clean
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
