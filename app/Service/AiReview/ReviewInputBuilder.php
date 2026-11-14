<?php

namespace App\Service\AiReview;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\Programs\Rounds\RoundRequiredDocument;

/** Gathers everything the review looks at (answers, locally extracted documents) and fingerprints it. */
class ReviewInputBuilder
{
    private const PROFILE_FIELDS = ['sector', 'stage', 'headquarters_location', 'business_description', 'problem_statement', 'solution', 'traction', 'team_size', 'funding_amount_requested'];

    public function __construct(private DocumentExtractor $extractor) {}

    /** Cheap hash that needs no extraction: answers + file identities. Used to detect stale reviews. */
    public function quickHash(ProgramApplication $application, ProgramRound $round): string
    {
        $answers = ApplicationRoundResponse::where(['application_id' => $application->id, 'round_id' => $round->id])->orderBy('question_id')->get(['question_id', 'response', 'file_path', 'file_paths', 'updated_at']);
        $docs = RoundRequiredDocument::where(['application_id' => $application->id, 'round_id' => $round->id])->orderBy('id')->get(['id', 'file_path', 'document_type', 'updated_at']);
        $questions = RoundCustomQuestion::where('round_id', $round->id)->orderBy('id')->get(['id', 'question_text', 'updated_at']);

        return hash('sha256', json_encode([
            $answers->map(fn ($a) => [$a->question_id, $a->response, $a->file_path, $a->file_paths, $a->updated_at?->timestamp])->all(),
            $docs->map(fn ($d) => [$d->id, $d->file_path, $d->document_type, $d->updated_at?->timestamp])->all(),
            $questions->map(fn ($q) => [$q->id, $q->question_text])->all(),
            $round->ai_review_config, $round->scoring_criteria, $round->review_method,
            config('ai_review.prompt_version'),
        ]));
    }

    /** @return array{questions:array,answers:array,documents:array,sources:array,extraction_issues:array,profile:array,notes:array,hash:string} */
    public function build(ProgramApplication $application, ProgramRound $round): array
    {
        $this->extractor->notes = [];
        $issues = [];
        $maxChars = (int) config('ai_review.max_answer_chars');

        $questions = RoundCustomQuestion::where('round_id', $round->id)->where('question_type', '!=', 'knockout')->orderBy('display_order')->get()
            ->map(fn ($q) => ['id' => $q->id, 'text' => $q->question_text, 'type' => $q->question_type, 'required' => (bool) $q->is_required])->all();
        $questionsById = collect($questions)->keyBy('id');

        $responses = ApplicationRoundResponse::where(['application_id' => $application->id, 'round_id' => $round->id])->get();
        $answers = [];
        $sources = [];
        $documents = [];
        $docCount = 0;
        $maxDocs = min((int) config('ai_review.max_documents_per_application'), (int) ($round->ai_review_config['document_rules']['max_documents'] ?? 100));

        foreach ($responses as $response) {
            $q = $questionsById->get($response->question_id);
            if (! $q) {
                continue;
            }

            $text = trim((string) $response->response);
            if (($q['type'] ?? '') !== 'file_upload' && $text !== '') {
                $text = mb_substr($text, 0, $maxChars);
                $answers[$q['id']] = ['text' => $text, 'response_id' => $response->id];
                $sources[] = ['type' => 'answer', 'id' => $response->id, 'label' => $q['text'], 'text' => $text, 'pages' => null];
            }

            foreach ($response->allFilePaths() as $index => $path) {
                $this->addDocument('answer_file', (int) $response->id, $path, $q['text'], $q['text'], $q['id'], $documents, $sources, $issues, $docCount, $maxDocs);
            }
        }

        $roundDocs = RoundRequiredDocument::where(['application_id' => $application->id, 'round_id' => $round->id])->orderBy('id')->get();
        foreach ($roundDocs as $doc) {
            $this->addDocument('round_document', (int) $doc->id, (string) $doc->file_path, (string) $doc->document_type, (string) ($doc->original_filename ?: $doc->document_type), null, $documents, $sources, $issues, $docCount, $maxDocs);
        }

        $profile = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $value = $application->getAttribute($field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $profile[$field] = mb_substr((string) $value, 0, 1500);
            }
        }

        $applicantName = trim((string) ($application->contact_person_name ?: ($application->user ? $application->user->first_name.' '.$application->user->last_name : '')));

        $hash = hash('sha256', json_encode([
            array_map(fn ($a) => $a['text'], $answers),
            array_map(fn ($d) => $d['file_hash'], $documents),
            $profile, $round->ai_review_config, $round->scoring_criteria, config('ai_review.prompt_version'),
        ]));

        return [
            'questions' => $questions, 'answers' => $answers, 'documents' => $documents, 'sources' => $sources,
            'extraction_issues' => $issues, 'profile' => $profile, 'applicant_name' => $applicantName, 'notes' => $this->extractor->notes, 'hash' => $hash,
        ];
    }

    private function addDocument(string $sourceType, int $sourceId, string $path, string $declaredType, string $label, ?int $questionId, array &$documents, array &$sources, array &$issues, int &$count, int $max): void
    {
        if ($path === '') {
            return;
        }
        $count++;
        if ($count > $max) {
            $issues[] = "Document not read (limit of {$max} documents per application): {$label}";

            return;
        }

        $ext = $this->extractor->extract($sourceType, $sourceId, $path, $declaredType, $label);
        $documents[] = [
            'extraction_id' => $ext->id, 'document_type' => $declaredType, 'label' => $label, 'question_id' => $questionId,
            'status' => $ext->status, 'file_hash' => $ext->file_hash, 'method' => $ext->method, 'page_count' => $ext->page_count,
            'path' => $path, 'source_type' => $sourceType, 'source_id' => $sourceId,
        ];

        if (in_array($ext->status, ['ok', 'ocr_ok'], true) && $ext->text_content) {
            $pages = $this->compactDocumentPages($ext->text_content, (int) config('ai_review.max_document_chars', 4000));
            $sources[] = ['type' => 'document', 'id' => $ext->id, 'label' => $label, 'text' => implode("\n\n", $pages), 'pages' => $pages];
        } else {
            $issues[] = match ($ext->status) {
                'excluded' => "Not read by AI (identity/verification document): {$label}",
                'too_large' => "Too large to read: {$label}",
                'unsupported' => "Unsupported file type: {$label}",
                default => "Could not read: {$label}".($ext->error ? " ({$ext->error})" : ''),
            };
        }
    }

    /**
     * Same low-token shape used by the frontend reader: remove empty/symbol/page-number lines and repeated
     * headers or footers, then cap the evidence for one file while retaining its real page numbers.
     * The full extraction remains cached privately; this only limits what is placed in an AI prompt.
     */
    private function compactDocumentPages(string $content, int $limit): array
    {
        $seen = [];
        $used = 0;
        $pages = [];

        foreach (explode("\f", $content) as $index => $page) {
            $kept = [];
            foreach (preg_split('/\R/u', $page) ?: [] as $line) {
                $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
                if ($line === '' || preg_match('/^[\W_]+$/u', $line) || preg_match('/^(?:page\s*)?\d+(?:\s*(?:of|\/)\s*\d+)?$/iu', $line)) {
                    continue;
                }

                $key = mb_strtolower($line);
                if (mb_strlen($line) > 3 && isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $remaining = $limit - $used;
                if ($remaining <= 0) {
                    break 2;
                }
                $line = mb_substr($line, 0, $remaining);
                $kept[] = $line;
                $used += mb_strlen($line) + 1;
                if ($used >= $limit) {
                    break;
                }
            }
            if ($kept) {
                $pages[$index + 1] = implode("\n", $kept);
            }
            if ($used >= $limit) {
                break;
            }
        }

        return $pages;
    }
}
