<?php
namespace App\Service\AiScore;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Misc\ErrorLogService;

class MatchScore
{
    public function program($request, $program_id)
    {
        $now=date("Y-m-d H:i"); $date=date('d M, h:i a',strtotime($now));
        $program = Program::where('id',$program_id)->first();
        $business = Listing::where('id',$request->business_id)->first();
        //$pitch = ProgramApplication::with('listing')->where('id',$pitch_id)->first();
        $score = 0;
        try{
            // Input data
            $business = [
                'sectors' => $request->sector,
                'region' => $request->headquarters_location,
                'stage' => $request->stage,
                'revenue' => (float) $request->revenue_last_12_months,
                'team_size' => $request->team_experience_avg_years,
                'impact_score' => $request->social_impact_areas,
                'milestones_achieved' => true,
                'documents_submitted' => $request->file('business_plan_file') != null ? true : false,

            ];

            $org = [
                'preferred_sectors' => $program->program_focus,
                'target_regions' => $program->regions,
                'target_stages' => $program->startup_stage_focus,
                'revenue' => $program->funding_per_business,
                'team_size' => $request->evaluation_criteria,
                'impact_score' => $program->social_impact_areas,
                'milestones_achieved' => true,
                'documents_submitted' => $program->program_brief_pdf != null ? true : false,
            ];

            // Sector Alignment (30%)
            if (!is_array($org['preferred_sectors'])) {
                $org['preferred_sectors'] = json_decode($org['preferred_sectors'], true)?? [];
            }
            $sectorScore = in_array($business['sectors'], $org['preferred_sectors']) ? 100 : 0;;
            $score += $sectorScore * 0.30;

            // Geographic Fit (15%)
            if (!is_array($org['target_regions'])) {
                $org['target_regions'] = json_decode($org['target_regions'],true)?? [];
            }
            if($business['region'] && $org['target_regions'])
                $geoScore = in_array($business['region'], $org['target_regions']) ? 100 : 0;
            else
                $geoScore = 0;
            $score += $geoScore * 0.15;

            // Startup Stage Compatibility (10%)
            if (!is_array($org['target_stages'])) {
                $org['target_stages'] = json_decode($org['target_stages'],true)?? [];
            }
            if($business['stage'] && $org['target_stages'])
                $stageScore = in_array($business['stage'], $org['target_stages']) ? 100 : 0;
            else
                $stageScore = 0;
            $score += $stageScore * 0.10;

            // Revenue Traction/Projection (10%)
            $revenueScore = $business['revenue'] >= $org['revenue'] ? 100 : 0;
            $score += $revenueScore * 0.10;

            // Team Experience / Background (10%)
            //$teamScore = $business['team_size'] >= $org['team_threshold'] ? 100 : 0;
            //$score += $teamScore * 0.10;

            // Impact Focus (10%)
            $impactScore = array_intersect($business['impact_score'], $org['impact_score']) ? 100 : 0;
            $score += $impactScore * 0.10;


            // Milestone Success (10%)
            $milestones = !is_array($request->input('milestones', '[]'))
                ? json_decode($request->input('milestones', '[]'), true)
                : $request->input('milestones', '[]');
            foreach ($milestones as $milestone) {
                if (!empty($milestone['has_deliverables'])) {
                    $business['milestones_achieved'] = true;
                    break;
                }
            }
            $milestoneScore = $business['milestones_achieved'] ? 100 : 100;
            $score += $milestoneScore * 0.10;

            // Document Completeness (5%)
            $round1 = ProgramRound::where('program_id', $program->id)->where('round_number', 1)->first();
            $requiredDocs = collect($round1?->required_documents ?? [])
                ->map(fn($d) => is_array($d) ? ($d['label'] ?? '') : $d)
                ->filter()->values()->toArray();

            if (empty($requiredDocs)) {
                $documentsSubmitted = true;
            } else {
                // Extract submitted document_types from round_documents array
                $submittedTypes = collect($request->input('round_documents', []))->pluck('document_type')
                    ->toArray();// e.g. ['pitch_deck', 'financials']

                $missingDocs = array_diff($requiredDocs, $submittedTypes);
                $documentsSubmitted = empty($missingDocs);
            }

            $documentScore = $documentsSubmitted ? 100 : 0;
            $score += $documentScore * 0.05;

            // Bonus (up to +20)
            $bonusInput = $request->bonus_points;
            if (!is_array($bonusInput)) {
                $bonusInput = $bonusInput ? [$bonusInput] : [];
            }

            $bo = 0;
            foreach ($bonusInput ?? [] as $bonus) {
                if (in_array($bonus, $program->bonus_points ?? [])) {
                    $bo += 5;
                }
            }
            $score += $bo;

            $score = min($score, 100);

        // Final Result
            if ($score >= 80) {
                $result = "Ideal Match";
            } elseif ($score >= 60) {
                $result = "Strong Match";
            } else {
                $result = "Needs Revision";
            }

//            $value_compare = [
//                'Sector Alignment' => implode(',', $business['sectors']) . ' <=> ' . implode(',', $org['preferred_sectors']),
//                'Geographic Fit' => $business['region'] . ' <=> ' . implode(',', $org['target_regions']),
//                'Startup Stage Compatibility' => $business['stage'] . ' <=> ' . implode(',', $org['target_stages']),
//                'Revenue Traction' => $business['revenue'] . ' <=> ' . $org['revenue'],
//            ];


            return response()->json([
                'score' => round($score, 2),
                'result' => $result,
                'score_breakdown' => [
                    'Sector Alignment' => round($sectorScore * 0.30, 2),
                    //'Sector Alignment2' => $org['preferred_sectors'],
                    'Geographic Fit' => round($geoScore * 0.15, 2),
                    'Startup Stage Compatibility' => round($stageScore * 0.10, 2),
                    'Revenue Traction' => round($revenueScore * 0.10, 2),
                    'Impact Focus' => round($impactScore * 0.10, 2),
                    'Milestone Success' => round($milestoneScore * 0.10, 2),
                    'Document Completeness' => round($documentScore * 0.05, 2),
                    'Bonus ' => $bo
                ],
                //'value_sent' => $request->all(),
                //'value_compare' => $value_compare
            ]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function capital($request, $capital_id)
    {
        $now = date("Y-m-d H:i");
        $date = date('d M, h:i a', strtotime($now));
        $capital = CapitalOffer::where('id', $capital_id)->first();
        $score = 0;

        try {
            // Input data
            $business = [
                'sectors' => $request->sector,
                'region' =>  $request->headquarters_location, // Modified: treat region as array
                'stage' => $request->stage,
                'revenue' => (float) $request->revenue_last_12_months,
                'team_size' => $request->team_experience_avg_years,
                'impact_score' => collect($request->social_impact_areas)
                    ->flatMap(fn($area) => explode(' ', strtolower(trim($area)))), // Modified: flatten for keywords
                'milestones_achieved' => false, // Modified: initially false
                'documents_submitted' => $request->file('business_plan') != null ? true : false,
            ];

            $org = [
                'preferred_sectors' => $capital->sectors,
                'target_regions' => $capital->regions,
                'target_stages' =>  $capital->startup_stage,
                'revenue' => $capital->per_startup_allocation,
                'team_size' => $request->exit_strategy,
                'impact_score' => collect(explode(' ', strtolower($capital->impact_objectives)))
                    ->map(fn($item) => trim($item)), // Modified: normalize for keyword match
                'milestones_achieved' => false,
                'documents_submitted' => $capital->offer_brief_file != null ? true : false,
            ];

            // Sector Alignment (30%)
            if (!is_array($org['preferred_sectors'])) {
                $org['preferred_sectors'] = json_decode($org['preferred_sectors'],true)?? [];
            }
            $sectorScore = in_array($business['sectors'], $org['preferred_sectors']) ? 100 : 0;
            $score += $sectorScore * 0.30;

            // Geographic Fit (15%) - Modified
            if (!is_array($org['target_regions'])) {
                $org['target_regions'] = json_decode($org['target_regions'],true)?? [];
            }
            if($business['region'] && $org['target_regions'])
                $geoScore = in_array($business['region'], $org['target_regions'])  ? 100 : 0;
            else
                $geoScore = 0;

            $score += $geoScore * 0.15;



            // Startup Stage Compatibility (10%)
            if (!is_array($org['target_stages'])) {
                $org['target_stages'] = json_decode($org['target_stages'],true)?? [];
            }
            if($business['stage'] && $org['target_stages'])
                $stageScore = in_array($business['stage'], $org['target_stages']) ? 100 : 0;
            else
                $stageScore = 0;
            $score += $stageScore * 0.10;

            // Revenue Traction/Projection (10%)
            $revenueScore = $business['revenue'] >= $org['revenue'] ? 100 : 0;
            $score += $revenueScore * 0.10;

            // Impact Focus (10%) - Modified
            $matchCount = 0;
            foreach ($business['impact_score'] as $item) {
                $matchCount += substr_count(implode(' ', $org['impact_score']->toArray()), $item);
            }
            $impactScore = $matchCount >= 2 ? 100 : 0;
            $score += $impactScore * 0.10;

            // Milestone Success (10%) - Modified
//            foreach ($request->file('milestones', []) as $milestone) {
//                if ($milestone['deliverables'] ?? null) {
//                    $business['milestones_achieved'] = true;
//                    break;
//                }
//            }
//          To be removed
            $milestones = json_decode($request->input('milestones', '[]'), true);
            foreach ($milestones as $milestone) {
                if (!empty($milestone['has_deliverables'])) {
                    $business['milestones_achieved'] = true;
                    break;
                }
            }


            $milestoneScore = $business['milestones_achieved'] ? 100 : 0;
            $score += $milestoneScore * 0.10;

            // Document Completeness (5%)
            $documentScore = $business['documents_submitted'] ? 100 : 0;
            $score += $documentScore * 0.05;

            // Bonus (up to +20) - Modified
            $bonus_points = explode(',', $request->bonus_points);
            $bo = 0;
            foreach ($bonus_points as $bonus) {
                if (in_array($bonus, ['gender_led', 'youth_led', 'rural_based', 'uses_local_sourcing'])) {
                    $bo += 5;
                }
            }
            $score += $bo;

            // Cap score
            $score = min($score, 100);

            // Final Result
            if ($score >= 80) {
                $result = "Ideal Match";
            } elseif ($score >= 60) {
                $result = "Strong Match";
            } else {
                $result = "Needs Revision";
            }

            return response()->json([
                'score' => round($score, 2),
                'result' => $result,
                'score_breakdown' => [
                    'Sector Alignment' => round($sectorScore * 0.30, 2),
                    'Geographic Fit' => round($geoScore * 0.15, 2),
                    'Startup Stage Compatibility' => round($stageScore * 0.10, 2),
                    'Revenue Traction' => round($revenueScore * 0.10, 2),
                    'Impact Focus' => round($impactScore * 0.10, 2),
                    'Milestone Success' => round($milestoneScore * 0.10, 2),
                    //'Milestone Success2' => $request->milestones,
                    'Document Completeness' => round($documentScore * 0.05, 2),
                    'Bonus' => $bo
                ]
            ]);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

}
