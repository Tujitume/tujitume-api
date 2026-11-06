<?php

use App\Models\Business\Listing;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Program\RoundMatchScore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Round 1 match scoring', function () {
    it('creates a score-enabled program, accepts an application, and previews its server-calculated score', function () {
        $industryId = DB::table('program_industries')->insertGetId([
            'name' => 'Agriculture',
            'url' => 'agriculture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $startDate = now()->toDateString();
        $deadline = now()->addDays(30)->toDateString();

        $created = $this->actingAsOrgOwner()->postJson('/api/v1/programs/create-program', [
            'program_title' => 'Youth Agribusiness Grant 2027',
            'program_industry_id' => $industryId,
            'total_program_amount' => 100000,
            'funding_per_business' => 10000,
            'application_start_date' => $startDate,
            'application_deadline' => $deadline,
            'program_type' => 'single_round',
            'total_rounds' => 1,
            'status' => 'published',
            'use_match_score' => true,
            'match_score_limit' => 50,
            'application_round' => [
                'rubric_mode' => 'weighted',
                'advancement_mode' => 'manual',
            ],
        ])->assertOk();

        $createdData = $created->json('data') ?? $created->json();
        $program = $createdData['program'] ?? [];
        $firstRound = $createdData['firstRound'] ?? $createdData['first_round'] ?? [];
        $programId = $program['id'] ?? null;
        $roundId = $firstRound['id'] ?? null;
        expect($programId)->not->toBeNull()
            ->and($program['use_match_score'] ?? null)->toBeTrue()
            ->and($program['match_score_limit'] ?? null)->toBe(50);

        $questionResponse = $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$roundId}/questions", [
            'question_text' => 'Where is your business based?',
            'question_type' => 'location',
            'is_required' => true,
            'options' => ['region', 'country'],
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 20,
            'match_answers' => [
                'region' => ['East Africa'],
                'country' => ['Kenya', 'Uganda', 'Tanzania'],
            ],
        ])->assertCreated();

        $questionId = $questionResponse->json('data.id') ?? $questionResponse->json('data.data.id');
        expect($questionId)->not->toBeNull()
            ->and($questionResponse->json('data.match_answers.country') ?? $questionResponse->json('data.data.match_answers.country'))
            ->toBe(['Kenya', 'Uganda', 'Tanzania']);

        $business = Listing::create([
            'user_id' => $this->applicantUser->id,
            'name' => 'Northstar Foods',
        ]);
        $answers = [[
            'question_id' => $questionId,
            'response' => json_encode(['region' => 'Central Africa', 'country' => 'Kenya']),
        ]];
        $applicationResponse = $this->actingAsApplicant()->postJson("/api/v1/programs/{$programId}/applications", [
            'business_id' => $business->id,
            'startup_name' => 'Northstar Foods',
            'contact_person_name' => 'Amina Otieno',
            'contact_person_email' => 'amina@example.test',
            'sector' => 'Food processing',
            'headquarters_location' => 'Nairobi, Kenya',
            'total_amount_requested' => 10000,
            'round_answers' => $answers,
        ])->assertCreated();

        $applicationId = $applicationResponse->json('data.id');
        expect($applicationResponse->json('data.match_score'))->toBe(20)
            ->and($applicationResponse->json('data.status'))->toBe('pending');
        $this->assertDatabaseHas('program_applications', [
            'id' => $applicationId,
            'match_score' => 20,
            'status' => 'pending',
        ]);

        $this->actingAsApplicant()->postJson("/api/v1/programs/match-score/{$programId}", [
            'round_answers' => $answers,
        ])->assertOk()
            ->assertJsonPath('data.match_score', 20)
            ->assertJsonPath('data.below_threshold', true)
            ->assertJsonPath('data.breakdown_for_applicant.0.status', 'matched')
            ->assertJsonMissingPath('data.breakdown_for_applicant.0.match_points')
            ->assertJsonMissingPath('data.breakdown_for_applicant.0.match_answers');
    });

    it('scores location by any matching configured part and caps summed checkbox option points', function () {
        $location = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Location',
            'question_type' => 'location',
            'options' => ['region', 'country'],
            'is_required' => true,
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 20,
            'match_answers' => ['region' => ['East Africa'], 'country' => ['Kenya', 'Uganda']],
        ]);
        $checkbox = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Groups',
            'question_type' => 'checkbox',
            'options' => ['Women', 'Youth', 'People with disabilities', 'Refugees'],
            'is_required' => true,
            'display_order' => 2,
            'use_in_match_score' => true,
            'match_points' => 15,
            'match_answers' => ['Women' => 6, 'Youth' => 6, 'People with disabilities' => 3],
        ]);

        $result = app(RoundMatchScore::class)->score($this->program, $this->round, [
            $location->id => ['response' => json_encode(['region' => 'Central Africa', 'country' => 'Kenya'])],
            $checkbox->id => ['response' => json_encode(['Women', 'Youth', 'People with disabilities'])],
        ]);

        expect($result['match_score'])->toBe(35.0)
            ->and($result['breakdown'][0]['earned_points'])->toBe(20.0)
            ->and($result['breakdown'][1]['earned_points'])->toBe(15.0);
    });

    it('awards partial ranking and matrix points by correct positions and rows', function () {
        $ranking = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Rank the options',
            'question_type' => 'ranking',
            'options' => ['A', 'B', 'C', 'D'],
            'is_required' => true,
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 10,
            'match_answers' => ['order' => ['A', 'B', 'C', 'D']],
        ]);
        $matrix = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Readiness',
            'question_type' => 'matrix',
            'options' => ['row:Records', 'row:Staff', 'col:Not ready', 'col:Ready'],
            'is_required' => true,
            'display_order' => 2,
            'use_in_match_score' => true,
            'match_points' => 10,
            'match_answers' => ['Records' => 'Ready', 'Staff' => 'Ready'],
        ]);

        $result = app(RoundMatchScore::class)->score($this->program, $this->round, [
            $ranking->id => ['response' => json_encode(['A', 'B', 'D', 'C'])],
            $matrix->id => ['response' => json_encode(['Records' => 'Ready', 'Staff' => 'Not ready'])],
        ]);

        expect($result['match_score'])->toBe(12.5)
            ->and($result['breakdown'][0]['earned_points'])->toBe(7.5)
            ->and($result['breakdown'][1]['earned_points'])->toBe(5.0);
    });

    it('enforces both file category and file type restrictions', function () {
        $scorer = app(RoundMatchScore::class);
        $pdf = UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf');
        $csv = UploadedFile::fake()->create('beneficiaries.csv', 10, 'text/csv');

        expect($scorer->fileMatchesQuestionOptions($pdf, ['document', 'type:pdf']))->toBeTrue()
            ->and($scorer->fileMatchesQuestionOptions($csv, ['document', 'type:pdf']))->toBeFalse()
            ->and($scorer->fileMatchesQuestionOptions($csv, ['beneficiary_list']))->toBeTrue()
            ->and($scorer->fileMatchesQuestionOptions($pdf, ['beneficiary_list']))->toBeFalse();
    });

    it('rejects scored questions outside Round 1 and prevents the point budget from exceeding 100', function () {
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/questions", [
            'question_text' => 'First scored question',
            'question_type' => 'yes_no',
            'is_required' => true,
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 90,
            'match_answers' => ['yes'],
        ])->assertCreated();

        $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$this->round->id}/questions", [
            'question_text' => 'Over budget',
            'question_type' => 'yes_no',
            'is_required' => true,
            'display_order' => 2,
            'use_in_match_score' => true,
            'match_points' => 11,
            'match_answers' => ['yes'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['match_points']);

        $roundTwo = ProgramRound::factory()->create([
            'program_id' => $this->program->id,
            'round_number' => 2,
        ]);
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/rounds/{$roundTwo->id}/questions", [
            'question_text' => 'Not scored in Round 2',
            'question_type' => 'yes_no',
            'is_required' => true,
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 10,
            'match_answers' => ['yes'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['use_in_match_score']);
    });

    it('keeps scoring and knockout answer keys private from applicants', function () {
        $scored = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Eligible location',
            'question_type' => 'location',
            'options' => ['country'],
            'is_required' => true,
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 20,
            'match_answers' => ['country' => ['Kenya']],
        ]);
        $knockout = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Is the business registered?',
            'question_type' => 'knockout',
            'is_required' => true,
            'display_order' => 2,
            'knockout_fail_value' => 'no',
        ]);

        $this->actingAsOrgOwner()->getJson("/api/v1/programs/rounds/{$this->round->id}/questions")
            ->assertOk()
            ->assertJsonPath('data.0.match_answers.country.0', 'Kenya')
            ->assertJsonPath('data.0.match_points', 20);

        $this->actingAsApplicant()->getJson("/api/v1/programs/rounds/{$this->round->id}/questions")
            ->assertForbidden();

        $applicationInfo = $this->actingAsApplicant()
            ->getJson("/api/v1/programs/{$this->program->id}/sme/applications")
            ->assertOk();
        $questions = $applicationInfo->json('application_round.questions')
            ?? $applicationInfo->json('data.application_round.questions');
        $knockoutQuestions = $applicationInfo->json('application_round.knockout_questions')
            ?? $applicationInfo->json('data.application_round.knockout_questions');

        expect($questions[0])->not->toHaveKeys(['match_answers', 'match_points', 'knockout_fail_value'])
            ->and($knockoutQuestions[0])->not->toHaveKeys(['match_answers', 'match_points', 'knockout_fail_value'])
            ->and($scored->applicantView())->not->toHaveKeys(['match_answers', 'match_points'])
            ->and($knockout->applicantView())->not->toHaveKey('knockout_fail_value');
    });

    it('clears application scores when program match scoring is disabled', function () {
        $question = RoundCustomQuestion::create([
            'round_id' => $this->round->id,
            'question_text' => 'Plan provided',
            'question_type' => 'short_answer',
            'is_required' => true,
            'display_order' => 1,
            'use_in_match_score' => true,
            'match_points' => 100,
            'match_answers' => null,
        ]);
        \App\Models\Programs\Rounds\ApplicationRoundResponse::create([
            'application_id' => $this->application->id,
            'round_id' => $this->round->id,
            'question_id' => $question->id,
            'response' => 'A plan',
        ]);
        app(RoundMatchScore::class)->recalculateApplication($this->application);
        expect((float) $this->application->fresh()->match_score)->toBe(100.0);

        $this->program->update(['use_match_score' => false]);
        app(RoundMatchScore::class)->recalculateProgram($this->program->fresh());

        expect($this->application->fresh()->match_score)->toBeNull()
            ->and($question->fresh()->use_in_match_score)->toBeTrue()
            ->and($question->fresh()->match_points)->toBe(100);
    });
});
