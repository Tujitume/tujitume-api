<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiFinding;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The AI settings of a round: validation rules, lock after publish, AI-only consent. Used by round store/update. */
class AiRoundSettings
{
    public function rules(): array
    {
        return [
            'review_method' => 'sometimes|in:manual,ai_assisted,ai_only',
            'confirm_ai_only' => 'sometimes|boolean',
            'ai_review_config' => 'nullable|array',
            'ai_review_config.dimensions' => 'nullable|array',
            'ai_review_config.dimensions.*' => ['string', Rule::in(ApplicationAiFinding::DIMENSIONS)],
            'ai_review_config.priority_keywords' => 'nullable|array|max:50',
            'ai_review_config.priority_keywords.*' => 'string|max:60',
            'ai_review_config.desired_responses' => 'nullable|array|max:50',
            'ai_review_config.desired_responses.*.question_id' => 'required|integer',
            'ai_review_config.desired_responses.*.expected' => 'required|string|max:500',
            'ai_review_config.instructions' => 'nullable|string|max:2000',
            'ai_review_config.document_rules' => 'nullable|array',
            'ai_review_config.document_rules.required_types' => 'nullable|array|max:20',
            'ai_review_config.document_rules.required_types.*' => 'string|max:60',
            'ai_review_config.document_rules.max_documents' => 'nullable|integer|min:1|max:20',
        ];
    }

    /**
     * Applies the rules to the validated data (in place) and returns what changed for the audit log.
     * @throws ValidationException
     */
    public function apply(array &$validated, ?ProgramRound $round, Request $request): array
    {
        $changes = [];
        $confirm = (bool) ($validated['confirm_ai_only'] ?? false);
        unset($validated['confirm_ai_only']);

        $current = $round?->review_method ?? 'manual';
        $new = $validated['review_method'] ?? $current;

        if (array_key_exists('review_method', $validated) && $new !== $current) {
            // Locked once the round is published: applicants have applied under the stated review method
            if ($round && $round->status !== 'draft') {
                throw ValidationException::withMessages(['review_method' => ['The review method cannot be changed after the round is published.']]);
            }
            $changes['review_method'] = ['from' => $current, 'to' => $new];
        }

        if ($new === 'ai_only') {
            if ($current !== 'ai_only' && ! $confirm) {
                throw ValidationException::withMessages(['confirm_ai_only' => ['Confirm that you want AI-only review (confirm_ai_only must be true).']]);
            }
            if ($current !== 'ai_only') {
                $validated['ai_only_acknowledged_at'] = now();
                $validated['ai_only_acknowledged_by'] = auth()->id();
                $changes['ai_only_acknowledged'] = true;
            }
            // No reviewers, orders or reviewer fees: the round is owner-only
            $validated['assignment_type'] = 'owner_only';
        } elseif (array_key_exists('review_method', $validated) && $current === 'ai_only') {
            $validated['ai_only_acknowledged_at'] = null;
            $validated['ai_only_acknowledged_by'] = null;
        }

        if (array_key_exists('ai_review_config', $validated)) {
            $config = $validated['ai_review_config'] ?? [];
            if ($round && ! empty($config['desired_responses'])) {
                $valid = RoundCustomQuestion::where('round_id', $round->id)->pluck('id')->all();
                foreach ($config['desired_responses'] as $i => $d) {
                    if (! in_array((int) $d['question_id'], $valid, true)) {
                        throw ValidationException::withMessages(["ai_review_config.desired_responses.{$i}.question_id" => ['The question does not belong to this round.']]);
                    }
                }
            }
            if ($config === [] || $config === null) {
                $validated['ai_review_config'] = null;
            }
            $changes['config_changed'] = true;
        }

        return $changes;
    }

    public function record(array $changes, ProgramRound $round): void
    {
        if (isset($changes['review_method'])) AiAudit::record('review_method_changed', $round->id, null, $changes['review_method']);
        if (isset($changes['ai_only_acknowledged'])) AiAudit::record('ai_only_acknowledged', $round->id);
        if (isset($changes['config_changed'])) AiAudit::record('config_changed', $round->id);
    }
}
