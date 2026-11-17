<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;
use App\Service\Program\RubricCalculator;
use App\Service\Program\ScoreAverager;

/** Writes human-source scores for the program owner so the existing averaging and finalize work unchanged. */
class ScoreImporter extends Importer
{
    public const OVERALL = 'Imported overall score';

    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $app = ProgramApplication::findOrFail($c->findOrFail('application', $v['application_ref']));
        $round = isset($v['round_number'])
            ? (ProgramRound::where('program_id', $app->program_id)->where('round_number', (int) $v['round_number'])->first() ?? throw new ImportRowException('That round does not exist.'))
            : ProgramRound::findOrFail($app->current_round_id);
        if ($round->status === 'finalized' && (int) $app->current_round_id === (int) $round->id) {
            throw new ImportRowException('The round is already finalized, so scores cannot be added.');
        }

        $criteria = collect($round->scoring_criteria ?? []);
        $entries = [];
        if (isset($v['total_score']) && empty($v['criterion'])) {
            if ($criteria->isEmpty()) {
                if (! $c->createdInBatch($round)) {
                    throw new ImportRowException('This round has no scoring criteria and was not created by this import, so a total score cannot be recorded. Give per-criterion scores.');
                }
                $c->updateModel($round, ['rubric_mode' => 'weighted', 'scoring_criteria' => [['name' => self::OVERALL, 'description' => 'Total score from the imported file', 'weight' => 100, 'score_range' => 100]]]);
                $criteria = collect($round->scoring_criteria);
            }
            if ($criteria->count() !== 1) {
                throw new ImportRowException('This round has several criteria, so a total score cannot be split. Give a score for each criterion.');
            }
            if ($round->rubric_mode === 'pass_fail') {
                throw new ImportRowException('A total score cannot be recorded on a pass/fail rubric.');
            }
            $entries[] = ['criterion' => $criteria->first()['name'], 'score' => $v['total_score']];
        } else {
            if (empty($v['criterion'])) {
                throw new ImportRowException('Give a criterion for this score.');
            }
            $name = $criteria->pluck('name')->first(fn ($n) => strcasecmp($n, $v['criterion']) === 0) ?? throw new ImportRowException("Unknown criterion \"{$v['criterion']}\" for this round.");
            $entries[] = array_filter(['criterion' => $name, 'score' => $v['criterion_score'], 'comment' => $v['comment'] ?? null], fn ($x) => $x !== null);
        }
        $calc = app(RubricCalculator::class);
        foreach ($entries as $e) {
            $max = $calc->maxScoreFor($criteria->firstWhere('name', $e['criterion']));
            if ($e['score'] > $max) {
                throw new ImportRowException("Score {$e['score']} is above the maximum ($max) for \"{$e['criterion']}\".");
            }
        }

        $owner = $c->owner();
        $score = ApplicationScore::where(['application_id' => $app->id, 'round_id' => $round->id, 'reviewer_id' => $owner->id])->first();
        $merged = collect($score?->criterion_scores ?? [])->keyBy('criterion');
        foreach ($entries as $e) {
            $merged[$e['criterion']] = $e;
        }
        $list = $merged->values()->all();
        $total = $calc->calculate($list, $round->scoring_criteria, $round->rubric_mode ?? 'simple_total');
        $before = $score ? ['criterion_scores' => $score->criterion_scores, 'total_score' => $score->total_score] : [];
        $score = ApplicationScore::updateOrCreate(
            ['application_id' => $app->id, 'round_id' => $round->id, 'reviewer_id' => $owner->id],
            ['criterion_scores' => $list, 'total_score' => $total, 'overall_comment' => null, 'scored_at' => now(), 'source' => 'human'],
        );
        $c->record($score, $before ? 'updated' : 'created', $before);

        if ((int) $app->current_round_id === (int) $round->id && in_array($app->round_status, ['draft', 'submitted', 'assigned', 'under_review', 'scored'], true)) {
            $snapshot = $app->only(['round_status', 'average_score', 'is_eligible_to_advance']);
            app(ScoreAverager::class)->apply($app, $round); // the same code manual scoring uses
            $c->record($app, 'updated', $snapshot);
        } else {
            ApplicationRoundHistory::where('application_id', $app->id)->where('round_id', $round->id)->update(['average_score' => app(ScoreAverager::class)->average($app->id, $round->id)]);
        }

        return ['application_score', $score->id];
    }
}
