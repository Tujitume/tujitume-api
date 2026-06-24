<?php

namespace App\Service\Misc;

use App\Models\Auth\User;

class TestData
{
    public function investorData()
    {
        $investor = new User();
        $investor->inv_range = ['10000-50000', '60000-100000'];
        $investor->turnover_range = ['5000-25000', '30000-80000'];
        $investor->interested_cats = ['tech', 'health', 'finance'];
        $investor->stage = ['seed', 'series_a'];
        $investor->social_impact_areas = 'climate change, gender equality';
        $investor->regions_focus = ['munich', 'germany', 'uk'];
        return $investor;

//        $investor->name = 'Malan';
//        $investor->gender = 'Male';
//        $investor->stage = ['Seed', 'Growth'];
//        $investor->interested_cats = ['Education', 'Healthcare'];
//        $investor->regions_focus = ['New York', 'NY'];
//        $investor->email = 'viva.malan166@gmail.com';
//        $investor->website = 'Test.com';
//        $investor->stripe_account_id = 'acct_1NcUipR0zSac5ey4';
//        $investor->facebook_id = '1776943631370498';
//        $investor->profile_image = 'images/users/1816968704970636.jpeg';
//        $investor->inv_range = ['10000-50000', '60000-100000'];
//        $investor->turnover_range = ['5000-25000', '30000-80000'];
//        $investor->social_impact_areas = 'test Education';
        //return $investor;

    }

    public function listingData()
    {
        // 📄 Fake listings
        $listings = [
            (object) [
                'id' => 12,
                'investment_needed' => 45000,
                'y_turnover' => '10000-20000',
                'stage' => 'seed',
                'social_impact_areas' => 'climate',
                'category' => 'Agri',
                'location' => 'Franz Josef Strauss, Munich, Germany',
                'rating' => 30,
                'rating_count' => 8,
                'likes' => 450,
            ],
            (object) [
                'id' => 13,
                'investment_needed' => 90000,
                'y_turnover' => '50000-120000',
                'stage' => 'series_a',
                'social_impact_areas' => 'education poverty reduction',
                'category' => 'education',
                'location' => 'Oxford Street, London, UK',
                'rating' => 65,
                'rating_count' => 7,
                'likes' => 420,
            ],
        ];
        return $listings;
    }

}
