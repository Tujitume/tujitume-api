<?php

use App\Models\Programs\ProgramWallet;

uses(Tests\Feature\Programs\ProgramTestCase::class);

it('provides one wallet relationship for a program', function () {
    $wallet = ProgramWallet::factory()->create(['program_id' => $this->program->id]);
    expect($this->program->fresh()->wallet->is($wallet))->toBeTrue();
});
