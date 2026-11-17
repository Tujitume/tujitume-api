<?php

namespace Tests\Feature\Programs;

/** Editing a program that already started must not trip over its (past) start date. */
class ProgramUpdateStartDateTest extends ProgramTestCase
{
    private function startedProgram(): void
    {
        $this->program->update([
            'status' => 'published',
            'application_start_date' => now()->subDays(7)->toDateString(),
            'application_deadline' => now()->addDays(20)->toDateString(),
        ]);
    }

    private function save(array $body)
    {
        return $this->actingAsOrgOwner()->postJson('/api/v1/programs/programs/'.$this->program->id, $body);
    }

    public function test_editing_another_field_with_the_unchanged_past_start_date_works(): void
    {
        $this->startedProgram();

        $this->save([
            'program_title' => 'Renamed while running',
            'application_start_date' => now()->subDays(7)->toDateString(),
            'application_deadline' => now()->addDays(20)->toDateString(),
        ])->assertOk();

        $fresh = $this->program->fresh();
        $this->assertSame('Renamed while running', $fresh->program_title);
        $this->assertSame(now()->subDays(7)->toDateString(), $fresh->application_start_date->toDateString());
    }

    public function test_the_start_date_of_a_started_program_cannot_be_moved(): void
    {
        $this->startedProgram();

        $this->save(['application_start_date' => now()->addDays(3)->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('application_start_date');
        $this->assertSame(now()->subDays(7)->toDateString(), $this->program->fresh()->application_start_date->toDateString());
    }

    public function test_a_deadline_sent_alone_is_checked_against_the_saved_start_date(): void
    {
        $this->startedProgram();

        $this->save(['application_deadline' => now()->addDays(40)->toDateString()])->assertOk();
        $this->save(['application_deadline' => now()->subDays(30)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('application_deadline');
    }

    public function test_a_program_that_has_not_started_can_still_move_its_start_date_but_not_into_the_past(): void
    {
        $this->program->update([
            'status' => 'draft',
            'application_start_date' => now()->addDays(5)->toDateString(),
            'application_deadline' => now()->addDays(30)->toDateString(),
        ]);

        $this->save(['application_start_date' => now()->addDays(8)->toDateString()])->assertOk();
        $this->save(['application_start_date' => now()->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('application_start_date');
    }
}
