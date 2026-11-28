<?php

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('gives the service provider their own services, bookings, earnings and recent bookings', function () {
    $provider = User::factory()->create(['user_type_id' => 3]);
    $other = User::factory()->create(['user_type_id' => 3]);
    $customer = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Amina', 'last_name' => 'W']);

    $service = DB::table('services')->insertGetId(['user_id' => $provider->id, 'name' => 'Bookkeeping', 'price' => 30000, 'category' => 'finance', 'details' => 'x', 'location' => 'Nairobi', 'active' => 1, 'rating' => 4, 'rating_count' => 2, 'image' => 'i', 'pin' => 'p', 'identification' => 'i', 'document' => 'd', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('services')->insert(['user_id' => $other->id, 'name' => 'Not mine', 'price' => 1, 'category' => 'x', 'details' => 'x', 'location' => 'x', 'active' => 1, 'image' => 'i', 'pin' => 'p', 'identification' => 'i', 'document' => 'd', 'created_at' => now(), 'updated_at' => now()]);

    $booking = DB::table('service_bookings')->insertGetId(['date' => now()->addDay(), 'service_id' => $service, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'status' => 'in_progress', 'paid' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('service_bookings')->insert(['date' => now()->addDay(), 'service_id' => $service, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('service_booking_milestones')->insert([
        ['booking_id' => $booking, 'service_id' => $service, 'booker_id' => $customer->id, 'title' => 'One', 'amount' => 10000, 'released' => 1, 'released_at' => now(), 'release_status' => 'completed', 'status' => 'Done', 'created_at' => now(), 'updated_at' => now()],
        ['booking_id' => $booking, 'service_id' => $service, 'booker_id' => $customer->id, 'title' => 'Two', 'amount' => 20000, 'released' => null, 'released_at' => null, 'release_status' => null, 'status' => 'In Progress', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $data = $this->actingAs($provider, 'sanctum')->getJson('/api/v1/dashboard/service-provider/stats')->assertOk()->json('data');

    expect($data['services'])->toMatchArray(['total' => 1, 'active' => 1, 'awaiting_reply' => 1, 'rating' => 4.0])
        ->and($data['bookings'])->toMatchArray(['total' => 2, 'this_month' => 2, 'active' => 1, 'completed' => 0])
        ->and((float) $data['earnings']['services'])->toBe(10000.0)
        ->and((float) $data['earnings']['pending'])->toBe(20000.0)
        ->and($data['recent_bookings'])->toHaveCount(2)
        ->and($data['recent_bookings'][0]['service_name'])->toBe('Bookkeeping')
        ->and(collect($data['booking_statuses'])->pluck('count', 'status')->all())->toMatchArray(['in_progress' => 1, 'pending' => 1]);
});
