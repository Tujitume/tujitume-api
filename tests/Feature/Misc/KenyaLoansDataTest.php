<?php

namespace Tests\Feature\Misc;

use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Business Loans lists read the data files committed in resources/bankjsonfiles/, with no *_JSON_URL env needed. */
class KenyaLoansDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_banks_and_saccos_lists_load_from_the_committed_files(): void
    {
        $user = User::factory()->create(['user_type_id' => 1]);

        foreach (['/api/v1/loans/kenya-business-loans', '/api/v1/loans/kenya-business-loans/saccos', '/api/v1/loans/kenya-business-loans/live'] as $url) {
            $response = $this->actingAs($user, 'sanctum')->getJson($url)->assertOk();
            $this->assertNull($response->json('error'), $url);
            $this->assertNotEmpty($response->json('items'), $url);
        }
    }
}
