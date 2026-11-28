<?php

use App\Models\Auth\User;
use App\Models\Shared\PMAudit;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Once a PM decided a business audit, their whole stage list answered 500: p_m_audits.decided_at was read as a string
 * and sent with ->toIso8601String(). It is a date now.
 */

it('still lists a business audit after the PM decided it', function () {
    $pm = User::factory()->create(['user_type_id' => 3]);
    PMAudit::create(['milestone_id' => 999, 'assigned_pm_id' => $pm->id, 'type' => 'pre_release', 'status' => 'accepted',
        'decision' => 'approved', 'decided_at' => now()->subHour(), 'notes' => 'Receipts match.']);

    $row = $this->actingAs($pm, 'sanctum')->getJson('/api/v1/pm-audits/pre_release')->assertOk()->json('data.0');

    expect($row['source'])->toBe('business')
        ->and($row['status'])->toBe('approved')
        ->and($row['decided_at'])->not->toBeNull();
    $this->actingAs($pm, 'sanctum')->getJson('/api/v1/pm-audits/summary')->assertOk();
});
