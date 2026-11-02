<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\ProgramIndustry;
use App\Models\Programs\ProgramMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function industry(string $name): ProgramIndustry
    {
        return ProgramIndustry::create(['name' => $name]);
    }

    private function authenticated(): static
    {
        return $this->actingAs(User::factory()->create(['user_type_id' => 4]), 'sanctum');
    }

    public function test_get_returns_global_defaults_and_industry_overrides(): void
    {
        $industry = $this->industry('Agriculture');
        ProgramMetadata::create(['program_industry_id' => null, 'key' => 'startup_stage_focus', 'value' => ['idea', 'seed']]);
        ProgramMetadata::create(['program_industry_id' => null, 'key' => 'bonus_points', 'value' => ['gender_led']]);
        ProgramMetadata::create(['program_industry_id' => $industry->id, 'key' => 'startup_stage_focus', 'value' => ['farm_ready']]);

        $this->authenticated()->getJson("/api/v1/programs/program-metadata?program_industry_id={$industry->id}")
            ->assertOk()
            ->assertExactJson(['startup_stage_focus' => ['farm_ready'], 'bonus_points' => ['gender_led']]);
    }

    public function test_post_creates_and_updates_multiple_fields_without_touching_omitted_fields(): void
    {
        $industry = $this->industry('Technology');
        ProgramMetadata::create(['program_industry_id' => $industry->id, 'key' => 'custom_key', 'value' => ['old']]);
        ProgramMetadata::create(['program_industry_id' => $industry->id, 'key' => 'omitted_key', 'value' => ['keep']]);

        $this->authenticated()->postJson('/api/v1/programs/program-metadata', [
            'program_industry_id' => $industry->id,
            'custom_key' => ['new'],
            'new_key' => ['nested' => true],
        ])->assertOk();

        $this->assertSame(['new'], ProgramMetadata::where('program_industry_id', $industry->id)->where('key', 'custom_key')->firstOrFail()->value);
        $this->assertSame(['nested' => true], ProgramMetadata::where('program_industry_id', $industry->id)->where('key', 'new_key')->firstOrFail()->value);
        $this->assertSame(['keep'], ProgramMetadata::where('program_industry_id', $industry->id)->where('key', 'omitted_key')->firstOrFail()->value);
    }

    public function test_metadata_is_isolated_by_industry(): void
    {
        $one = $this->industry('Industry One');
        $two = $this->industry('Industry Two');
        ProgramMetadata::create(['program_industry_id' => $one->id, 'key' => 'startup_stage_focus', 'value' => ['one']]);
        ProgramMetadata::create(['program_industry_id' => $two->id, 'key' => 'startup_stage_focus', 'value' => ['two']]);

        $this->authenticated()->getJson("/api/v1/programs/program-metadata/{$two->id}")
            ->assertOk()
            ->assertExactJson(['startup_stage_focus' => ['two']]);
    }
}
