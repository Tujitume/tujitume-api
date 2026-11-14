<?php

namespace App\Service\AiReview;

use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\Ai\ApplicationDocumentExtraction;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;

/** Runs the model call(s): redact, fit to the provider's budget (map-reduce), validate, repair once, verify quotes. */
class AiReviewAnalyzer
{
    /** Providers that rejected our key during this run (the caller tells the owner once). */
    public array $authFailures = [];

    /** Maximum tokens kept for a normal report; small provider limits get a proportional reserve. */
    private const MAX_OUTPUT_RESERVE = 2500;

    public function __construct(
        private ProviderRouter $router,
        private RateGovernor $governor,
        private PrivacyGate $gate,
        private ReviewPromptBuilder $prompts,
        private ResponseValidator $validator,
        private QuoteVerifier $verifier,
        private Redactor $redactor,
    ) {}

    /**
     * @return array{data:array, findings:array, quality:array, usage:array, provider:string, model:string, notes:array}
     *
     * @throws AiUnavailableException
     */
    public function analyze(ApplicationAiReview $review, ProgramRound $round, Program $program, array $input, bool $withScores): array
    {
        $usage = ['tokens_in' => 0, 'tokens_out' => 0, 'calls' => 0];
        $notes = [];
        $this->redactor->lastCount = 0;
        $name = $input['applicant_name'] ?? null;

        // 1. Redacted blocks: answers whole, documents by page
        $blocks = [];
        foreach ($input['sources'] as $source) {
            if ($source['type'] === 'answer') {
                $isName = ScoreGuard::kind((string) $source['label']) === 'name';
                $blocks[] = ['type' => 'answer', 'id' => $source['id'], 'label' => $source['label'], 'text' => $isName ? $this->redactor->describeName((string) $source['text']) : $this->redactor->redact($source['text'], $name)];
            } else {
                // What a reviewer would notice about the document at a glance (it says it is fictional, has a placeholder email...), told to the model with its label
                $flags = DocumentFlags::of((string) ($source['text'] ?? ''));
                $label = $source['label'].($flags ? ' (notes: '.implode('; ', $flags).')' : '');
                foreach ($source['pages'] ?? [1 => $source['text']] as $page => $text) {
                    if (trim($text) === '') {
                        continue;
                    }
                    $blocks[] = ['type' => 'document', 'id' => $source['id'], 'label' => $label, 'page' => $page, 'text' => $this->redactor->redact($text, $name)];
                }
            }
        }

        // 2. Fit the provider budget; too big means map (cached per-document digests) then reduce
        $limit = $this->tokenLimit($program);
        $reserveOut = $this->outputReserve($limit);
        $budgetChars = $this->budgetChars($limit, $reserveOut);
        $totalChars = array_sum(array_map(fn ($b) => mb_strlen($b['text']), $blocks));
        $messagesProbe = $this->prompts->review($program, $round, $input, [], $withScores);
        $overhead = mb_strlen(json_encode($messagesProbe));
        $available = max(2000, $budgetChars - $overhead);

        if ($totalChars > $available) {
            $notes[] = 'The application was too long for one pass, so documents were summarised first (map-reduce).';
            $blocks = $this->mapReduceBlocks($blocks, $input, $program, $review, $available, $usage, $notes);
        }
        $blocks = $this->trimToFit($blocks, $available, $notes);

        // 3. Reduce / single pass. The wrappers around each block and the JSON escaping add to the size that the character count above does not
        // see, so measure the real request and trim the content until it fits the provider's limit (a request that can never fit is refused, not delayed).
        for ($i = 0; $i < 6; $i++) {
            $messages = $this->prompts->review($program, $round, $input, $blocks, $withScores);
            $estimate = (int) ceil(mb_strlen(json_encode($messages)) / 3.3) + $reserveOut;
            if ($estimate <= $limit) {
                break;
            }
            $kept = array_sum(array_map(fn ($b) => mb_strlen($b['text']), $blocks));
            $blocks = $this->trimToFit($blocks, max(1200, $kept - (int) (($estimate - $limit + 150) * 3.3)), $notes);
        }
        $notes = array_values(array_unique($notes));
        $criteria = collect($round->scoring_criteria ?? [])->pluck('name')->all();
        $schema = $this->prompts->schema($withScores);
        $repairs = 0;

        $response = $this->call(new LlmRequest($messages, $schema, $reserveOut), $program, $review, $usage);
        $result = $this->validator->validate($response->json, $criteria, $withScores);
        if ($result['errors']) {
            $repairs = 1;
            $response = $this->call(new LlmRequest($this->prompts->repair($messages, $response->text, $result['errors']), $schema, $reserveOut), $program, $review, $usage);
            $result = $this->validator->validate($response->json, $criteria, $withScores);
        }
        if ($result['errors']) {
            throw new \UnexpectedValueException('The model did not return a valid report after one repair: '.implode('; ', array_slice($result['errors'], 0, 3)));
        }

        // A name, email and phone number are checked in code, whatever the model made of the masked value
        if ($withScores) {
            $result['data']['scores'] = app(ScoreGuard::class)->apply($result['data']['scores'], $round, $input);
        }

        // 4. Verify every quote against the locally extracted source text
        $sources = collect($input['sources'])->all();
        $docMeta = collect($input['documents'])->keyBy('extraction_id');
        $findings = [];
        $cited = 0;
        $verified = 0;
        foreach ($result['data']['findings'] as $f) {
            $isSourced = in_array($f['source_type'], ['answer', 'document'], true);
            $ok = false;
            $ref = null;
            if ($isSourced) {
                $cited++;
                $ref = ['type' => $f['source_type'], 'id' => $f['source_id'], 'page' => $f['page']];
                $src = $this->verifier->find($ref, $sources);
                $ok = $src ? $this->verifier->verify($f['quote'], $ref, $sources) : false;
                if ($ok) {
                    $verified++;
                }
                $ref['label'] = $src['label'] ?? null;
                if ($f['source_type'] === 'document' && $docMeta->has($f['source_id'])) {
                    $d = $docMeta[$f['source_id']];
                    $ref += ['file_path' => $d['path'], 'document_source_type' => $d['source_type'], 'document_source_id' => $d['source_id']];
                } elseif ($f['source_type'] === 'answer') {
                    $ref += ['response_id' => $f['source_id']];
                }
            }
            $findings[] = $f + ['quote_verified' => $ok, 'source_ref' => $ref];
        }

        $quality = [
            'verified_quote_ratio' => $cited ? round($verified / $cited, 3) : 1.0,
            'cited_findings' => $cited, 'verified_findings' => $verified,
            'schema_repairs' => $repairs, 'redactions' => $this->redactor->lastCount,
        ];

        return [
            'data' => $result['data'], 'findings' => $findings, 'quality' => $quality, 'usage' => $usage,
            'provider' => $response->provider, 'model' => $response->model, 'notes' => $notes,
        ];
    }

    // ── budget & chunking ─────────────────────────────────────────────────────

    /** The smallest context or tokens-per-minute limit among the providers this program may use. */
    private function tokenLimit(Program $program): int
    {
        $tokens = PHP_INT_MAX;
        foreach ($this->gate->allowedProviders($program) as $p) {
            $cfg = config("ai_review.providers.{$p}");
            $tokens = min($tokens, (int) $cfg['context_tokens'], (int) $cfg['limits']['tpm']);
        }

        return $tokens === PHP_INT_MAX ? 6000 : $tokens;
    }

    private function outputReserve(int $limit): int
    {
        return min(self::MAX_OUTPUT_RESERVE, max(1200, (int) floor($limit * 0.25)));
    }

    private function budgetChars(int $limit, int $reserveOut): int
    {
        return max(2500, (int) (($limit - $reserveOut - 400) * 3.3));
    }

    private function trimToFit(array $blocks, int $available, array &$notes): array
    {
        $used = 0;
        $out = [];
        $cut = false;
        foreach ($blocks as $b) {
            $len = mb_strlen($b['text']);
            if ($used + $len > $available) {
                $room = $available - $used;
                if ($room > 300) {
                    $b['text'] = mb_substr($b['text'], 0, $room);
                    $out[] = $b;
                    $used = $available;
                }
                $cut = true;

                continue;
            }
            $out[] = $b;
            $used += $len;
        }
        if ($cut) {
            $notes[] = 'Some content was cut to fit the AI provider limits.';
        }

        return $out;
    }

    /** Replace long documents with cached digests so answers + facts fit one reduce call. */
    private function mapReduceBlocks(array $blocks, array $input, Program $program, ApplicationAiReview $review, int $available, array &$usage, array &$notes): array
    {
        $answers = array_values(array_filter($blocks, fn ($b) => $b['type'] === 'answer'));
        $docsById = [];
        foreach ($blocks as $b) {
            if ($b['type'] === 'document') {
                $docsById[$b['id']][] = $b;
            }
        }

        $answerChars = array_sum(array_map(fn ($b) => mb_strlen($b['text']), $answers));
        $perDoc = max(800, (int) (($available - $answerChars) / max(1, count($docsById))));
        $out = $answers;
        // Include the full map prompt in the estimate. Previously only document characters were budgeted, so a
        // 6k-token Amari limit could reject the request before extraction summaries were even produced.
        $limit = $this->tokenLimit($program);
        $mapOutput = min(1200, max(700, (int) floor($limit * 0.18)));
        $mapOverhead = mb_strlen(json_encode($this->prompts->map('D0', '')));
        $mapContentBudget = max(1200, (int) (($limit - $mapOutput - 300) * 3.3) - $mapOverhead);
        $perChunk = max(1200, min((int) ($available * 0.8), $mapContentBudget));

        foreach ($docsById as $id => $pages) {
            $ext = ApplicationDocumentExtraction::find($id);
            $facts = $ext?->digest['facts'] ?? null;
            if ($facts === null) {
                $facts = [];
                $text = collect($pages)->map(fn ($p) => "[page {$p['page']}]\n{$p['text']}")->implode("\n");
                foreach (str_split($text, $perChunk) as $chunk) {
                    $resp = $this->call(new LlmRequest($this->prompts->map("D{$id}", $chunk), $this->prompts->mapSchema(), $mapOutput), $program, $review, $usage);
                    foreach (($resp->json['facts'] ?? []) as $fact) {
                        if (is_array($fact) && is_string($fact['fact'] ?? null)) {
                            $facts[] = ['fact' => mb_substr($fact['fact'], 0, 300), 'page' => $fact['page'] ?? null, 'quote' => mb_substr((string) ($fact['quote'] ?? ''), 0, 300)];
                        }
                    }
                }
                $ext?->update(['digest' => ['facts' => $facts]]);
            }
            $lines = [];
            foreach ($facts as $f) {
                $lines[] = (isset($f['page']) ? "p.{$f['page']}: " : '').$f['fact'].(! empty($f['quote']) ? ' — "'.$f['quote'].'"' : '');
            }
            $out[] = ['type' => 'document', 'id' => $id, 'label' => $pages[0]['label'].' (summary of facts)', 'text' => mb_substr(implode("\n", $lines), 0, $perDoc)];
        }

        return $out;
    }

    // ── provider call with failover ───────────────────────────────────────────

    private function call(LlmRequest $request, Program $program, ApplicationAiReview $review, array &$usage): LlmResponse
    {
        $skip = [];
        $kinds = [];
        $est = (int) ceil(mb_strlen(json_encode($request->messages)) / 3.3) + $request->maxTokens;
        $organizationId = $this->gate->organizationFor($program)?->id;

        for ($i = 0; $i < 6; $i++) {
            $pick = $this->router->pick($program, $est, $skip);
            if (! $pick['client']) {
                if ($skip) {
                    // Only limits (rate / quota) are worth waiting for; outages and bad keys will not fix themselves
                    $onlyLimits = $kinds && ! array_diff($kinds, [LlmException::RATE_LIMITED, LlmException::QUOTA_EXHAUSTED]);
                    $wait = $onlyLimits ? collect($skip)->map(fn ($p) => $this->governor->cooldownRemaining($p))->filter()->min() : null;
                    throw new AiUnavailableException($wait ?: null, $this->authFailures ? 'auth_error' : ($onlyLimits ? 'quota' : 'provider_unavailable'), (bool) $this->authFailures, $this->authFailures[0] ?? null);
                }
                throw new AiUnavailableException($pick['wait'], $pick['reason'] ?? 'unavailable');
            }

            $client = $pick['client'];
            try {
                $response = $client->complete($request);
                $this->governor->reconcile($client->provider(), $est, $response->tokensIn + $response->tokensOut, $response->model, $review->id, $organizationId, $response->tokensIn, $response->tokensOut);
                $usage['tokens_in'] += $response->tokensIn;
                $usage['tokens_out'] += $response->tokensOut;
                $usage['calls']++;

                return $response;
            } catch (LlmException $e) {
                $this->governor->refund($client->provider(), $est);
                $skip[] = $client->provider();
                $kinds[] = $e->kind;
                match ($e->kind) {
                    LlmException::AUTH_ERROR => $this->authFail($client->provider()),
                    LlmException::QUOTA_EXHAUSTED => $this->governor->cooldown($client->provider(), max($e->retryAfter ?? 0, 3600)),
                    LlmException::RATE_LIMITED => $this->governor->cooldown($client->provider(), max($e->retryAfter ?? 0, 60)),
                    LlmException::BAD_REQUEST => throw new \UnexpectedValueException('The provider refused the request: '.$e->getMessage()),
                    default => $this->governor->cooldown($client->provider(), (int) config('ai_review.provider_cooldown_seconds')),
                };
            }
        }

        throw new AiUnavailableException(null, 'provider_unavailable');
    }

    private function authFail(string $provider): void
    {
        $this->authFailures[] = $provider;
        $this->governor->cooldown($provider, 3600);
    }
}
