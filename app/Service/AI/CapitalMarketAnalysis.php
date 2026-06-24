<?php
namespace App\Service\AI;
use App\Models\Capital\CapitalOffer;

class CapitalMarketAnalysis
{
    const WEIGHTS = [
        'investment' => 0.10,
        'turnover' => 0.08,
        'stage' => 0.05,
        'impact' => 0.10,
        'sector' => 0.04,
        'region' => 0.03,
    ];

    public function trendingData(): array
    {
        $capitalData = CapitalOffer::select('total_capital_available', 'per_startup_allocation', 'sectors')->get();

        $sectorCounts = collect();
        $allocationCounts = collect();

        foreach ($capitalData as $data) {
            // --- Count sectors (now an array) ---
            $sectors = is_array($data->sectors) ? $data->sectors : explode(',', $data->sectors ?? '');
            foreach ($sectors as $sector) {
                $sector = strtolower(trim($sector)); // normalize casing
                if (!empty($sector)) {
                    $sectorCounts[$sector] = ($sectorCounts[$sector] ?? 0) + 1;
                }
            }

            // --- Calculate allocation percentage ---
            $allocation = (float) $data->per_startup_allocation;
            $total = (float) $data->total_capital_available;

            if ($allocation > 0 && $total > 0) {
                $percent = round(($allocation / $total) * 100, 0); // round to nearest whole %
                $label = $percent . '%';

                $allocationPercentCounts[$label] = ($allocationPercentCounts[$label] ?? 0) + 1;
            }
        }

        $trendingSectors = collect($sectorCounts)->sortDesc()->take(10);
        $trendingAllocations = collect($allocationPercentCounts)->sortDesc()->take(10);

        return [
            'sectors' => $trendingSectors,
            'allocations' => $trendingAllocations,
        ];
    }

    // S C O R E   F U N C T I O N S

//    protected function investmentScore($business) {
//        return ($business['amount_requested'] >= $this->investorData['investment_min'] &&
//            $business['amount_requested'] <= $this->investorData['investment_max']) ? 100 : 0;
//    }


}

