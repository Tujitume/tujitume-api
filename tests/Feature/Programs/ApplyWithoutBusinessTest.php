<?php

use App\Models\Business\Listing;
use App\Models\Programs\ProgramApplication;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// Applying needs no business: business_id, startup_name, sector and headquarters_location are optional.
// A business that is named must be the applicant's own.

beforeEach(function () {
    $this->program->update([
        'application_start_date' => now()->subDay()->toDateString(), 'application_deadline' => now()->addDays(20)->toDateString(),
        'status' => 'published', 'visible' => 1,
    ]);
    $this->round->update(['status' => 'published']);
    ProgramApplication::whereKey($this->application->id)->delete();
});

function applyWithout($test, array $extra = [])
{
    return $test->actingAs($test->applicantUser, 'sanctum')->postJson("/api/v1/programs/{$test->program->id}/applications", $extra + [
        'contact_person_name' => 'Amina Otieno', 'contact_person_email' => 'amina@example.test', 'total_amount_requested' => 1000,
    ]);
}

it('accepts an application with no business, name, sector or place', function () {
    $id = applyWithout($this)->assertCreated()->json('data.id');
    $app = ProgramApplication::find($id);
    expect($app->business_id)->toBeNull()
        ->and($app->startup_name)->toBe('Amina Otieno')
        ->and($app->sector)->toBe('')
        ->and($app->headquarters_location)->toBe('');
});

it('keeps the applicant\'s own business when one is named', function () {
    $own = Listing::create(['user_id' => $this->applicantUser->id, 'name' => 'Shamba Fresh']);
    $id = applyWithout($this, ['business_id' => $own->id, 'startup_name' => 'Shamba Fresh'])->assertCreated()->json('data.id');
    expect((int) ProgramApplication::find($id)->business_id)->toBe($own->id);
});

it('refuses a business that belongs to someone else', function () {
    $other = Listing::create(['user_id' => $this->orgUser->id, 'name' => 'Not yours']);
    applyWithout($this, ['business_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('business_id');
    expect(ProgramApplication::where('program_id', $this->program->id)->count())->toBe(0);
});
