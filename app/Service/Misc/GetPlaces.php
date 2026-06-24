<?php
namespace App\Service\Misc;
use Illuminate\Support\Facades\Http;

class GetPlaces
{
    public function search($query)
    {
        // Call Photon API
        $response = Http::get('https://photon.komoot.io/api/', [
            'q' => $query,
        ]);

        if ($response->failed()) {
            return response()->json(['error' => 'Failed to fetch places'], 500);
        }

        $data = $response->json();

        // Build simplified results
        $results = [];
        foreach ($data['features'] ?? [] as $feature) {
            $name = $feature['properties']['name'] ?? '';
            $city = $feature['properties']['city'] ?? '';
            $country = $feature['properties']['country'] ?? '';
            $coordinates = $feature['geometry']['coordinates'] ?? [null, null];

            // Skip if no name or country
            if (!$name || !$country) continue;

            $results[] = [
                'label' => $city ? "$name, $city, $country" : "$name, $country",
                'name' => $name,
                'city' => $city,
                'country' => $country,
                'lat' => $coordinates[1] ?? null,
                'lng' => $coordinates[0] ?? null,
            ];

            // Limit to first 10 results
            if (count($results) >= 10) break;
        }

        return $results;
    }

}
