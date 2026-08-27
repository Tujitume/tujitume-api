<?php

use App\Models\Programs\Rounds\ProgramRound;

uses(Tests\Feature\Programs\ProgramTestCase::class);
it('belongs to its program', function () { $round = ProgramRound::factory()->create(['program_id' => $this->program->id]); expect($round->program->is($this->program))->toBeTrue(); });
