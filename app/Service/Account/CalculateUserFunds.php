<?php

namespace App\Service\Account;
use App\Models\Auth\User;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\StartupPitches;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\User\UserResource;


class CalculateUserFunds
{

    public function calculateProgramFunds(User $user)
    {
        $user = $user->load('program_profile.role');
        $role = $user->program_profile?->role?->name ?? 'super-admin';
        $ownerId = ($role === 'editor' || $role === 'viewer') ? $user->program_profile->program_owner_id : $user->id;

        $total_program_amount = Program::where('user_id', $ownerId)->sum('total_program_amount');
        $available_program_amount = Program::where('user_id', $ownerId)->sum('available_amount');

        return [
            'user' => new UserResource($user),
            'role' => $role,
            'total_funds' => $total_program_amount,
            'available_funds' => $available_program_amount,
        ];
    }

    public function calculateCapitalFunds(User $user)
    {
        $user = $user->load('capital_profile.role');
        $role = $user->capital_profile->role?->name ?? 'super-admin';
        $ownerId = ($role === 'editor' || $role === 'viewer') ? $user->capital_profile->capital_owner_id : $user->id;

        $total_capital_available = CapitalOffer::where('user_id', $ownerId)->sum('total_capital_available');
        $available_amount = CapitalOffer::where('user_id', $ownerId)->sum('available_amount');

        return [
            'user' => new UserResource($user),
            'role' => $role,
            'total_funds' => $total_capital_available,
            'available_funds' => $available_amount,
        ];
    }

    public function calculateSMEFunds(User $user)
    {
        $pitches = ProgramApplication::with('program_milestones')->where('user_id', $user->id)->latest()->get();
        $capitalPitches = StartupPitches::with('capital_milestones')->where('user_id', $user->id)->latest()->get();

        $totalFunds = 0; $availableFunds = 0; $matchScore = 0; $cntG = 0; $cntC = 0;

        foreach ($pitches as $pitch) {
            $matchScore += $pitch->score;
            $availableFunds += $pitch->total_amount_requested;
            foreach ($pitch->program_milestones as $mile) {
                if ($mile->status == 1) $totalFunds += $mile->amount;
            }
            if ($pitch->status == 1) $cntG++;
        }

        foreach ($capitalPitches as $pitch) {
            $matchScore += $pitch->score;
            $availableFunds += $pitch->total_amount_requested;
            foreach ($pitch->capital_milestones as $mile) {
                if ($mile->status == 1) $totalFunds += $mile->amount;
            }
            if ($pitch->status == 1) $cntC++;
        }

        $totalPitches = $pitches->count() + $capitalPitches->count();
        $avgMatchScore = $totalPitches > 0 ? $matchScore / $totalPitches : 0;
        $successG = $pitches->count() > 0 ? ($cntG / $pitches->count()) * 100 : 0;
        $successC = $capitalPitches->count() > 0 ? ($cntC / $capitalPitches->count()) * 100 : 0;
        $successRate = round((($successG + $successC) / 2), 2);

        return [
            'user' => new UserResource($user),
            'total_funds' => $totalFunds,
            'available_funds' => $availableFunds,
            'success_rate' => $successRate,
            'avg_match_score' => $avgMatchScore,
        ];
    }
}
