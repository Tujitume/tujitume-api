<?php

use App\Models\Programs\Rounds\RoundCustomQuestion;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

function makeRoundWithQuestions($test, int $count = 3): array
{
    $org = (fn () => $this->actingAsOrgOwner())->bindTo($test, $test); // the helper is protected on the test case
    $industryId = DB::table('program_industries')->insertGetId(['name' => 'Agriculture ' . uniqid(), 'url' => 'agriculture-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    $created = $org()->postJson('/api/v1/programs/create-program', [
        'program_title' => 'Question delete test',
        'program_industry_id' => $industryId,
        'total_program_amount' => 100000,
        'funding_per_business' => 10000,
        'application_start_date' => now()->toDateString(),
        'application_deadline' => now()->addDays(30)->toDateString(),
        'program_type' => 'single_round',
        'total_rounds' => 1,
        'status' => 'published',
        'application_round' => ['rubric_mode' => 'weighted', 'advancement_mode' => 'manual'],
    ])->assertOk();
    $data = $created->json('data') ?? $created->json();
    $roundId = ($data['firstRound'] ?? $data['first_round'])['id'];

    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        $response = $org()->postJson("/api/v1/programs/rounds/{$roundId}/questions", [
            'question_text' => "Question {$i}", 'question_type' => 'short_answer', 'is_required' => false, 'display_order' => $i,
        ])->assertCreated();
        $ids[] = $response->json('data.id') ?? $response->json('data.data.id');
    }

    return [$roundId, $ids];
}

describe('Deleting round questions', function () {
    it('deletes one question at questions/{question}', function () {
        [$roundId, $ids] = makeRoundWithQuestions($this);

        $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/questions/{$ids[0]}")->assertOk();

        expect(RoundCustomQuestion::where('round_id', $roundId)->pluck('id')->all())->toBe([$ids[1], $ids[2]]);
    });

    it('deletes several questions in one request', function () {
        [$roundId, $ids] = makeRoundWithQuestions($this);

        $response = $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/rounds/{$roundId}/questions", ['ids' => [$ids[0], $ids[2]]])->assertOk();
        expect($response->json('deleted') ?? $response->json('data.deleted'))->toBe(2); // responses may be wrapped in "data"

        expect(RoundCustomQuestion::where('round_id', $roundId)->pluck('id')->all())->toBe([$ids[1]]);
    });

    it('only deletes questions that belong to that round, and refuses an empty list or another user', function () {
        [$roundId, $ids] = makeRoundWithQuestions($this, 2);
        [, $otherIds] = makeRoundWithQuestions($this, 1);

        $response = $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/rounds/{$roundId}/questions", ['ids' => [$otherIds[0]]])->assertOk();
        expect($response->json('deleted') ?? $response->json('data.deleted'))->toBe(0);
        expect(RoundCustomQuestion::whereKey($otherIds[0])->exists())->toBeTrue();

        $this->actingAsOrgOwner()->deleteJson("/api/v1/programs/rounds/{$roundId}/questions", ['ids' => []])->assertStatus(422);
        $this->actingAsApplicant()->deleteJson("/api/v1/programs/rounds/{$roundId}/questions", ['ids' => [$ids[0]]])->assertStatus(403);
        expect(RoundCustomQuestion::where('round_id', $roundId)->count())->toBe(2);
    });
});
