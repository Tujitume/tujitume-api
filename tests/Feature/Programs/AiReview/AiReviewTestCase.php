<?php

namespace Tests\Feature\Programs\AiReview;

use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Programs\ProgramTestCase;

abstract class AiReviewTestCase extends ProgramTestCase
{
    protected FakeLlmClient $llm;
    public RoundCustomQuestion $question;
    public ApplicationRoundResponse $answer;

    public const ANSWER = 'We run a women-led maize processing company serving 200 farmers with 40% revenue growth.';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->enableAi();
        $this->llm = new FakeLlmClient('groq');
        app()->instance('ai_review.client.groq', $this->llm);
    }

    protected function enableAi(array $extra = []): void
    {
        config([
            'ai_review.enabled' => true, 'ai_review.default_provider' => 'groq', 'ai_review.fallbacks' => [],
            'ai_review.external_calls_disabled' => false,
            'ai_review.providers.groq.api_key' => 'test-key',
            'ai_review.providers.groq.allow_applicant_data' => true, 'ai_review.providers.groq.trains_on_data' => false,
            'ai_review.providers.groq.context_tokens' => 200000,
            'ai_review.providers.groq.limits' => ['rpm' => 1000, 'tpm' => 1000000, 'rpd' => 100000, 'reset_tz' => 'UTC'],
        ] + $extra);
    }

    /** Turn the base round into an AI round with one question, one answer and a two-criterion rubric. */
    protected function aiRound(string $method = 'ai_assisted'): ProgramRound
    {
        $this->round->update([
            'review_method' => $method, 'rubric_mode' => 'weighted',
            'scoring_criteria' => [['name' => 'Impact', 'weight' => 60, 'score_range' => 10], ['name' => 'Viability', 'weight' => 40, 'score_range' => 10]],
            'assignment_type' => $method === 'ai_only' ? 'owner_only' : 'internal',
            'ai_review_config' => ['dimensions' => ['business_viability', 'traction'], 'priority_keywords' => ['women-led', 'agritech'], 'instructions' => 'Prefer rural impact.'],
            'close_date' => now()->addDays(10), 'review_period_end' => now()->addDays(20),
        ]);
        $this->question = RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => 'Describe your business', 'question_type' => 'long_text', 'is_required' => true, 'display_order' => 1]);
        $this->answer = ApplicationRoundResponse::create(['application_id' => $this->application->id, 'round_id' => $this->round->id, 'question_id' => $this->question->id, 'response' => self::ANSWER]);
        $this->application->update(['round_status' => 'submitted']);

        return $this->round->refresh();
    }

    protected function patchRound(array $body)
    {
        return $this->actingAsOrgOwner()->patchJson("/api/v1/programs/rounds/{$this->round->id}", $body);
    }

    protected function assignReviewer(): void
    {
        ReviewerApplicationAssignment::create(['round_id' => $this->round->id, 'application_id' => $this->application->id, 'reviewer_id' => $this->reviewerUser->id, 'status' => 'assigned']);
    }

    protected function report(array $override = []): array
    {
        return array_replace_recursive([
            'summary' => 'A women-led processor with clear growth.',
            'findings' => [[
                'dimension' => 'business_viability', 'polarity' => 'strength', 'title' => 'Revenue growth', 'body' => 'Revenue grew by 40%.',
                'confidence' => 'high', 'source_type' => 'answer', 'source_id' => $this->answer->id, 'quote' => 'women-led maize processing company',
            ]],
            'scores' => [['criterion' => 'Impact', 'score' => 8, 'rationale' => 'Serves 200 farmers'], ['criterion' => 'Viability', 'score' => 7, 'rationale' => 'Growing revenue']],
            'missing_items' => [],
        ], $override);
    }

    protected function otherOrgUser(): User
    {
        $u = User::factory()->create(['user_type_id' => 4]);
        $this->createVerifiedKyc($u, 'organization');

        return $u;
    }
}
