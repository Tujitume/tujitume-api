<?php
namespace App\Service\AI;
use App\Models\Programs\Program;
use Illuminate\Support\Collection;

class SuggestionsForAProgram
{
    protected Program $pivot;
    protected int $limit = 6;

    public static function for(Program $program): self
    {
        return new self($program);
    }

    public function __construct(Program $program)
    {
        $this->pivot = $program;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): Collection
    {
        $candidates = Program::query()
            ->where('id', '!=', $this->pivot->id)
            ->where('visible', 1)
            ->select('*')->get();

        return $candidates
            ->map(fn ($program) => [
                'program' => $program,
                'score'   => $this->calculateScore($program),
            ])
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($this->limit)
            //->pluck('listing')
            ->values();
    }

    protected function calculateScore(Program $program): float
    {
        $score = 0;

        if (
            $program->program_industry_id !== null
            && $program->program_industry_id === $this->pivot->program_industry_id
        ) {
            $score += 60;
        }

        if ($program->program_type === $this->pivot->program_type) {
            $score += 15;
        }

        $candidateFunding = (float) $program->funding_per_business;
        $pivotFunding = (float) $this->pivot->funding_per_business;
        if ($candidateFunding > 0 && $pivotFunding > 0) {
            $score += 25 * min($candidateFunding, $pivotFunding) / max($candidateFunding, $pivotFunding);
        }

        return round($score, 2);
    }
}
