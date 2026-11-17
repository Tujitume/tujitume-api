<?php

namespace Tests\Feature\CustomFields;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramCustomField;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Programs\ProgramTestCase;

uses(ProgramTestCase::class);

function field(array $o = []): array
{
    return $o + ['label' => 'Business age (years)', 'type' => 'number', 'audience' => 'application', 'is_required' => true];
}

function threeFields($t): void
{
    $base = "/api/v1/programs/{$t->program->id}/fields";
    $t->actingAs($t->orgUser, 'sanctum')->postJson($base, ['label' => 'Interested places', 'type' => 'multi_select', 'options' => ['Niagara', 'NY', 'London'], 'is_scoring' => true,
        'scoring' => ['enabled' => true, 'weight' => 20, 'match_type' => 'in_set', 'mode' => 'proportional', 'target' => ['Niagara', 'London']]])->assertCreated();
    $t->actingAs($t->orgUser, 'sanctum')->postJson($base, ['label' => 'Business age (years)', 'type' => 'number', 'is_required' => true, 'is_scoring' => true,
        'scoring' => ['enabled' => true, 'weight' => 30, 'match_type' => 'range', 'target' => ['min' => 2, 'max' => 10]]])->assertCreated();
    $t->actingAs($t->orgUser, 'sanctum')->postJson($base, ['label' => 'Business plan PDF', 'type' => 'file', 'is_required' => true, 'validation' => ['mimes' => ['pdf'], 'max_kb' => 5120], 'is_scoring' => true,
        'scoring' => ['enabled' => true, 'weight' => 10, 'match_type' => 'provided']])->assertCreated();
}

it('creates fields with an immutable slug key and lists them with a scoring summary', function () {
    threeFields($this);
    $res = $this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/fields")->assertOk();
    expect(array_column($res->json('data.fields'), 'key'))->toBe(['interested_places', 'business_age_years', 'business_plan_pdf'])
        ->and($res->json('data.scoring_summary.has_scoring_field'))->toBeTrue()->and($res->json('data.scoring_summary.normalized_weights.business_age_years'))->toBe(50);
    $same = $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$this->program->id}/fields", field(['label' => 'Business age (years)!']))->assertCreated();
    expect($same->json('data.key'))->toBe('business_age_years_2');
    $id = ProgramCustomField::where('key', 'interested_places')->value('id');
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/fields/$id", ['label' => 'Where do you want to go?'])->assertOk();
    expect(ProgramCustomField::find($id)->key)->toBe('interested_places');
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/fields/$id", ['type' => 'text'])->assertStatus(422);
});

it('rejects bad scoring definitions', function () {
    $base = "/api/v1/programs/{$this->program->id}/fields";
    $bad = fn (array $f) => $this->actingAsOrgOwner()->postJson($base, $f)->assertStatus(422);
    $bad(['label' => 'T', 'type' => 'text', 'is_scoring' => true, 'scoring' => ['enabled' => true, 'weight' => 5, 'match_type' => 'range', 'target' => ['min' => 1]]]);
    $bad(['label' => 'S', 'type' => 'select', 'options' => ['a', 'b'], 'is_scoring' => true, 'scoring' => ['enabled' => true, 'weight' => 5, 'match_type' => 'in_set', 'target' => ['c']]]);
    $bad(['label' => 'N', 'type' => 'number', 'is_scoring' => true, 'scoring' => ['enabled' => true, 'weight' => 5, 'match_type' => 'range', 'target' => ['min' => 9, 'max' => 2]]]);
    $bad(['label' => 'W', 'type' => 'boolean', 'is_scoring' => true, 'scoring' => ['enabled' => true, 'weight' => 5000, 'match_type' => 'equals', 'target' => true]]);
    $bad(['label' => 'C', 'type' => 'select', 'options' => []]);
    $bad(['label' => 'B', 'type' => 'boolean', 'is_bonus' => true]);
    $this->actingAsOrgOwner()->postJson($base, ['label' => 'Info only', 'type' => 'text', 'audience' => 'info', 'is_scoring' => true, 'scoring' => ['weight' => 5, 'match_type' => 'provided']])->assertCreated();
    expect(ProgramCustomField::where('key', 'info_only')->value('is_scoring'))->toBeFalse();
});

it('allows only 50 active fields and keeps other organizations out', function () {
    $base = "/api/v1/programs/{$this->program->id}/fields";
    for ($i = 0; $i < 50; $i++) {
        ProgramCustomField::create(['program_id' => $this->program->id, 'key' => "f$i", 'label' => "F$i", 'type' => 'text', 'audience' => 'application']);
    }
    $this->actingAsOrgOwner()->postJson($base, field())->assertStatus(422);
    ProgramCustomField::where('key', 'f0')->update(['is_archived' => true]);
    $this->actingAsOrgOwner()->postJson($base, field())->assertCreated();

    $other = User::factory()->create(['user_type_id' => 4]);
    $org = Organization::factory()->create(['owner_user_id' => $other->id]);
    $other->update(['organization_id' => $org->id]);
    $id = ProgramCustomField::first()->id;
    $this->actingAs($other, 'sanctum')->getJson($base)->assertForbidden();
    $this->actingAs($other, 'sanctum')->postJson($base, field())->assertForbidden();
    $this->actingAs($other, 'sanctum')->patchJson("/api/v1/programs/fields/$id", ['label' => 'x'])->assertForbidden();
    $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/programs/fields/$id")->assertForbidden();
    $this->actingAsApplicant()->getJson($base)->assertForbidden();
});

it('locks the definition once applications exist, archives fields with answers, hard-deletes unused ones, and reorders', function () {
    $base = "/api/v1/programs/{$this->program->id}/fields";
    $a = $this->actingAsOrgOwner()->postJson($base, ['label' => 'Sector', 'type' => 'select', 'options' => ['Agri', 'Health']])->json('data.id');
    $b = $this->actingAsOrgOwner()->postJson($base, field())->json('data.id');
    $this->actingAsOrgOwner()->postJson("$base/reorder", ['ordered_ids' => [$b, $a]])->assertOk();
    expect(ProgramCustomField::find($b)->display_order)->toBe(1)->and(ProgramCustomField::find($a)->display_order)->toBe(2);

    // the setUp application already exists: only label/help/order/visibility and appended options may change
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/fields/$a", ['is_required' => true])->assertStatus(422);
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/fields/$a", ['options' => ['Health']])->assertStatus(422);
    $this->actingAsOrgOwner()->patchJson("/api/v1/programs/fields/$a", ['options' => ['Agri', 'Health', 'Education'], 'label' => 'Business sector'])->assertOk();
    expect(ProgramCustomField::find($a)->options)->toBe(['Agri', 'Health', 'Education']);

    \App\Models\Programs\ProgramCustomFieldValue::create(['field_id' => $a, 'entity_type' => 'application', 'entity_id' => $this->application->id, 'value_text' => 'Agri']);
    $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/fields/$a")->assertOk()->assertJsonPath('data.result', 'archived');
    $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/fields/$b")->assertOk()->assertJsonPath('data.result', 'deleted');
    expect(ProgramCustomField::find($a)->is_archived)->toBeTrue()->and(ProgramCustomField::find($b))->toBeNull();
});

it('creates a program with only core fields, or with inline custom fields, and clones definitions on duplicate', function () {
    $industry = \App\Models\ProgramIndustry::firstOrCreate(['name' => 'Agriculture']);
    $core = ['program_title' => 'Core', 'program_industry_id' => $industry->id, 'total_program_amount' => 100000, 'funding_per_business' => 25000, 'disbursement_type' => 'supplier', 'program_type' => 'single_round',
        'currency' => 'KES', 'application_start_date' => now()->addDay()->toDateString(), 'application_deadline' => now()->addMonth()->toDateString()];
    $this->actingAsOrgOwner()->postJson('/api/v1/programs/create-program', $core)->assertOk();
    $this->actingAsOrgOwner()->postJson('/api/v1/programs/create-program', ['program_title' => 'With fields', 'custom_fields' => [field(), ['label' => 'Region', 'type' => 'select', 'options' => ['A', 'B']]]] + $core)->assertOk();
    $with = \App\Models\Programs\Program::where('program_title', 'With fields')->first();
    expect($with->customFields()->count())->toBe(2);
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$with->id}/duplicate")->assertSuccessful();
    $copy = \App\Models\Programs\Program::where('program_title', 'With fields Copy')->first();
    expect($copy->customFields()->pluck('key')->all())->toBe(['business_age_years', 'region'])->and(\App\Models\Programs\ProgramCustomFieldValue::count())->toBe(0);
});

it('hides scoring from applicants on get_program and shows public info values', function () {
    threeFields($this);
    $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$this->program->id}/fields", ['label' => 'About the funder', 'type' => 'textarea', 'audience' => 'info'])->assertCreated();
    $this->actingAsOrgOwner()->putJson("/api/v1/programs/{$this->program->id}/info-values", ['values' => ['about_the_funder' => '<b>We fund</b> farms']])->assertOk();
    expect($this->actingAsOrgOwner()->getJson("/api/v1/programs/{$this->program->id}/fields")->json('data.info_values'))->toBe(['about_the_funder' => 'We fund farms']);
    $res = $this->actingAsApplicant()->getJson("/api/v1/programs/get_program/{$this->program->id}")->assertOk();
    $json = json_encode($res->json());
    $fields = $res->json('data.program.application_fields') ?? $res->json('program.application_fields') ?? $res->json('data.application_fields');
    expect(array_column($fields, 'key'))->toBe(['interested_places', 'business_age_years', 'business_plan_pdf'])
        ->and($json)->not->toContain('"scoring"')->not->toContain('normalized_weight')->not->toContain('"target"')->and($json)->toContain('We fund farms');
});

it('validates applicant answers from the stored definitions, stores them, and computes the server score and breakdown', function () {
    Storage::fake('s3');
    threeFields($this);
    $this->program->update(['use_match_score' => true]);
    $applicant = User::factory()->create(['user_type_id' => 1]);
    $url = "/api/v1/programs/{$this->program->id}/applications";
    $core = ['startup_name' => 'Agro', 'contact_person_name' => 'A', 'contact_person_email' => 'a@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 1000];

    $this->actingAs($applicant, 'sanctum')->post($url, $core, ['Accept' => 'application/json'])->assertStatus(422);                       // required custom answers missing
    $this->actingAs($applicant, 'sanctum')->post($url, $core + ['custom_answers' => ['interested_places' => ['Mars'], 'business_age_years' => 5, 'business_plan_pdf' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')], 'match_score' => 100], ['Accept' => 'application/json'])->assertStatus(422);   // option not in the stored list
    $this->actingAs($applicant, 'sanctum')->post($url, $core + ['custom_answers' => ['interested_places' => ['Niagara'], 'business_age_years' => 5, 'business_plan_pdf' => UploadedFile::fake()->create('p.exe', 10)]], ['Accept' => 'application/json'])->assertStatus(422);       // wrong file type
    $ok = $this->actingAs($applicant, 'sanctum')->post($url, $core + ['match_score' => 100, 'custom_answers' => ['interested_places' => ['Niagara'], 'business_age_years' => 5, 'business_plan_pdf' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json']);
    $ok->assertSuccessful();

    $app = ProgramApplication::where('startup_name', 'Agro')->first();
    // hand calculation: places 20*0.5 + age 30*1 + plan 10*1 = 50 of 60 = 83.33 -> 83 (the client-sent 100 is ignored)
    expect((float) $app->match_score)->toBe(83.0)->and($app->score_breakdown['source'])->toBe('custom_fields')->and($app->score_breakdown['breakdown'][0]['match'])->toBe(0.5)
        ->and(\App\Models\Programs\ProgramCustomFieldValue::where('entity_id', $app->id)->count())->toBe(3);
    Storage::disk('s3')->assertExists(\App\Models\Programs\ProgramCustomFieldValue::whereHas('field', fn ($q) => $q->where('key', 'business_plan_pdf'))->value('value_text'));
});

it('previews the match score without saving, showing applicants only matched / partial / not matched', function () {
    threeFields($this);
    $res = $this->actingAsApplicant()->post("/api/v1/programs/match-score/{$this->program->id}", ['custom_answers' => ['interested_places' => ['Niagara', 'London'], 'business_age_years' => 1]], ['Accept' => 'application/json'])->assertOk();
    // places 20*1 + age 30*0 + plan 10*0 = 20 of 60 = 33
    expect($res->json('data.match_score'))->toBe(33)->and(array_column($res->json('data.breakdown_for_applicant'), 'status'))->toBe(['matched', 'not matched', 'not matched'])
        ->and(json_encode($res->json()))->not->toContain('weight')->not->toContain('target');
    expect(\App\Models\Programs\ProgramCustomFieldValue::count())->toBe(0);
});

it('shows a guest the same application fields on the public program page, never the scoring', function () {
    threeFields($this);
    $this->program->update(['status' => 'published']);
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $res = $this->getJson("/api/v1/public/programs/{$this->program->public_id}")->assertOk();
    $fields = $res->json('data.program.application_fields') ?? $res->json('program.application_fields') ?? $res->json('data.application_fields');
    expect(array_column($fields, 'key'))->toBe(['interested_places', 'business_age_years', 'business_plan_pdf'])
        ->and(json_encode($res->json()))->not->toContain('"scoring"')->not->toContain('normalized_weight');
});

it('keeps the program page working before the custom-fields migration has run', function () {
    // The tables do not exist yet (the migration is a hand-over step): programs must still load, be applied to and be duplicated
    \Illuminate\Support\Facades\Schema::dropIfExists('program_custom_field_values');
    \Illuminate\Support\Facades\Schema::dropIfExists('program_custom_fields');
    $flag = new \ReflectionProperty(\App\Service\Program\CustomFields\CustomFieldValueService::class, 'tablesFound');
    $flag->setValue(null, false);

    $res = $this->actingAsOrgOwner()->getJson("/api/v1/programs/get_program/{$this->program->id}")->assertOk();
    expect($res->json('program.application_fields') ?? $res->json('data.program.application_fields'))->toBe([]);

    $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$this->program->id}/duplicate")->assertSuccessful();
});
