<?php

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Communication\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

// ISSUE-13 (e2e test 2026-11-27): the notification `date` text was the server's UTC time, 3 hours behind created_at
// for Kenyan users. It is rebuilt from created_at in the viewer's zone.
it('shows the notification date in the viewer\'s time zone', function () {
    $user = User::factory()->create(['user_type_id' => 1]);
    UserSetting::updateOrCreate(['user_id' => $user->id], ['timezone' => 'Africa/Nairobi']);
    Carbon::setTestNow(Carbon::parse('2026-11-27 01:46:00', 'UTC'));
    Notifications::create(['receiver_id' => $user->id, 'customer_id' => $user->id, 'text' => 'Hello', 'link' => 'dashboard',
        'new' => 1, 'visible' => 1, 'date' => '27 Nov, 01:46 am', 'type' => 'program']);

    $row = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications')->assertOk()->json('data.0');
    expect($row['date'])->toBe('27 Nov, 04:46 am');
    Carbon::setTestNow();
});
