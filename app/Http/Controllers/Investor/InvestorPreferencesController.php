<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Users\InvestorProfile;
use Illuminate\Http\Request;

/**
 * PATCH users/me/investor-preferences: an individual investor changes what they look for (the Business matches scores
 * use it). The same fields as sign-up; each list keeps the values as the sign-up form writes them.
 */
class InvestorPreferencesController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        if ((int) $user->user_type_id !== 2) {
            return response()->json(['message' => 'Only investors have investment preferences.'], 403);
        }

        $data = $request->validate([
            'inv_range' => 'required|array|min:1|max:10',
            'inv_range.*' => 'string|max:100',
            'turnover_range' => 'required|array|min:1|max:10',
            'turnover_range.*' => 'string|max:100',
            'interested_sectors' => 'required|array|min:1|max:50',
            'interested_sectors.*' => 'string|max:100',
            'stage' => 'required|array|min:1|max:20',
            'stage.*' => 'string|max:100',
            'regions_focus' => 'required|array|min:1|max:100',
            'regions_focus.*' => 'string|max:100',
            'social_impact_areas' => 'nullable|array|max:50',
            'social_impact_areas.*' => 'string|max:100',
            'past_investment' => 'nullable|string|max:1000',
        ]);

        $profile = InvestorProfile::updateOrCreate(['user_id' => $user->id], [
            ...$data,
            'social_impact_areas' => $data['social_impact_areas'] ?? null,
            'past_investment' => $data['past_investment'] ?? null,
        ]);

        return response()->json(['message' => 'Your investment preferences were saved. Your matches use them now.', 'data' => $profile]);
    }
}
