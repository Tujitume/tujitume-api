<?php

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Models\ImportRow;
use Tests\Feature\ProgramImport\ImportTestCase;

uses(ImportTestCase::class);

it('stages, validates and commits a review import and lands the documented state', function () {
    $file = $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $b = $this->newBatch('review', $file);
    $r = $this->prepare($b);

    expect($r['blockers'])->toBe([])->and($r['ready'])->toBeTrue()
        ->and($b->refresh()->status)->toBe('ready')
        ->and(ImportRow::where('batch_id', $b->id)->where('status', 'valid')->count())->toBe(7);

    $b = $this->commit($b);
    expect($b->status)->toBe('completed')->and($b->stats['commit']['imported'])->toBe(7);

    $p = Program::first();
    expect($p->status)->toBe('under_review')->and($p->visible)->toBeFalse()->and((float) $p->total_program_amount)->toBe(400000.0)
        ->and($p->max_awardees)->toBe(4)->and($p->user_id)->toBe($this->orgUser->id)->and($p->organization_id)->toBe($this->org->id)
        ->and(ProgramWallet::where('program_id', $p->id)->value('status'))->toBe('inactive');
    expect(ProgramRound::where('round_number', 1)->value('status'))->toBe('finalized')
        ->and(ProgramRound::where('round_number', 2)->value('status'))->toBe('published');
    $a = ProgramApplication::where('startup_name', 'Agro One')->first();
    expect($a->status)->toBe('approved')->and($a->round_status)->toBe('submitted')->and($a->current_round_id)->toBe(ProgramRound::where('round_number', 2)->value('id'))
        ->and($a->program_owner_id)->toBe($this->orgUser->id)->and($a->user->user_type_id)->toBe(1)->and($a->user->password)->toBeNull()
        ->and($a->roundHistory ?? null)->not->toBeFalse();
    expect(\Illuminate\Support\Facades\DB::table('application_round_history')->where('application_id', $a->id)->count())->toBe(2);
});

it('does nothing twice when the same file is imported again', function () {
    $file = $this->xlsx(['program' => [$this->programRow()], 'rounds' => $this->roundRows(), 'applications' => $this->appRows(2)]);
    $this->commit(tap($this->newBatch('review', $file), fn ($b) => $this->prepare($b)));
    $b2 = $this->newBatch('review', $file);
    $this->prepare($b2);
    $b2 = $this->commit($b2);

    expect(Program::count())->toBe(1)->and(ProgramApplication::count())->toBe(2)->and(ProgramRound::count())->toBe(2)
        ->and($b2->stats['commit']['imported'])->toBe(0)->and($b2->stats['commit']['skipped'])->toBe(7);
});
