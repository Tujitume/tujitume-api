<?php

namespace App\Service\Program;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoundMatchScore
{
    private const KNOCKOUT_TYPES = ['knockout', 'knockout_yes_no'];

    private const TYPE_ALIASES = [
        'long_answer' => 'long_text',
        'checkboxes' => 'checkbox',
        'rating_scale' => 'rating',
        'phone_number' => 'phone',
        'url_website' => 'url',
        'date_time' => 'datetime',
        'currency_money' => 'currency',
        'matrix_grid' => 'matrix',
    ];

    private const PHONE_CODES = [
        'af' => '93', 'al' => '355', 'dz' => '213', 'ad' => '376', 'ao' => '244', 'ag' => '1', 'ar' => '54', 'am' => '374',
        'au' => '61', 'at' => '43', 'az' => '994', 'bs' => '1', 'bh' => '973', 'bd' => '880', 'bb' => '1', 'by' => '375',
        'be' => '32', 'bz' => '501', 'bj' => '229', 'bt' => '975', 'bo' => '591', 'ba' => '387', 'bw' => '267', 'br' => '55',
        'bn' => '673', 'bg' => '359', 'bf' => '226', 'bi' => '257', 'kh' => '855', 'cm' => '237', 'ca' => '1', 'cv' => '238',
        'cf' => '236', 'td' => '235', 'cl' => '56', 'cn' => '86', 'co' => '57', 'km' => '269', 'cg' => '242', 'cd' => '243',
        'cr' => '506', 'ci' => '225', 'hr' => '385', 'cu' => '53', 'cy' => '357', 'cz' => '420', 'dk' => '45', 'dj' => '253',
        'dm' => '1', 'do' => '1', 'ec' => '593', 'eg' => '20', 'sv' => '503', 'gq' => '240', 'er' => '291', 'ee' => '372',
        'sz' => '268', 'et' => '251', 'fj' => '679', 'fi' => '358', 'fr' => '33', 'ga' => '241', 'gm' => '220', 'ge' => '995',
        'de' => '49', 'gh' => '233', 'gr' => '30', 'gd' => '1', 'gt' => '502', 'gn' => '224', 'gw' => '245', 'gy' => '592',
        'ht' => '509', 'hn' => '504', 'hu' => '36', 'is' => '354', 'in' => '91', 'id' => '62', 'ir' => '98', 'iq' => '964',
        'ie' => '353', 'il' => '972', 'it' => '39', 'jm' => '1', 'jp' => '81', 'jo' => '962', 'kz' => '7', 'ke' => '254',
        'ki' => '686', 'kw' => '965', 'kg' => '996', 'la' => '856', 'lv' => '371', 'lb' => '961', 'ls' => '266', 'lr' => '231',
        'ly' => '218', 'li' => '423', 'lt' => '370', 'lu' => '352', 'mg' => '261', 'mw' => '265', 'my' => '60', 'mv' => '960',
        'ml' => '223', 'mt' => '356', 'mh' => '692', 'mr' => '222', 'mu' => '230', 'mx' => '52', 'fm' => '691', 'md' => '373',
        'mc' => '377', 'mn' => '976', 'me' => '382', 'ma' => '212', 'mz' => '258', 'mm' => '95', 'na' => '264', 'nr' => '674',
        'np' => '977', 'nl' => '31', 'nz' => '64', 'ni' => '505', 'ne' => '227', 'ng' => '234', 'kp' => '850', 'mk' => '389',
        'no' => '47', 'om' => '968', 'pk' => '92', 'pw' => '680', 'ps' => '970', 'pa' => '507', 'pg' => '675', 'py' => '595',
        'pe' => '51', 'ph' => '63', 'pl' => '48', 'pt' => '351', 'qa' => '974', 'ro' => '40', 'ru' => '7', 'rw' => '250',
        'kn' => '1', 'lc' => '1', 'vc' => '1', 'ws' => '685', 'sm' => '378', 'st' => '239', 'sa' => '966', 'sn' => '221',
        'rs' => '381', 'sc' => '248', 'sl' => '232', 'sg' => '65', 'sk' => '421', 'si' => '386', 'sb' => '677', 'so' => '252',
        'za' => '27', 'kr' => '82', 'ss' => '211', 'es' => '34', 'lk' => '94', 'sd' => '249', 'sr' => '597', 'se' => '46',
        'ch' => '41', 'sy' => '963', 'tw' => '886', 'tj' => '992', 'tz' => '255', 'th' => '66', 'tl' => '670', 'tg' => '228',
        'to' => '676', 'tt' => '1', 'tn' => '216', 'tr' => '90', 'tm' => '993', 'tv' => '688', 'ug' => '256', 'ua' => '380',
        'ae' => '971', 'gb' => '44', 'us' => '1', 'uy' => '598', 'uz' => '998', 'vu' => '678', 'va' => '379', 've' => '58',
        'vn' => '84', 'ye' => '967', 'zm' => '260', 'zw' => '263',
    ];

    public function prepareQuestionForSave(ProgramRound $round, array $data, ?RoundCustomQuestion $question = null): array
    {
        $type = $data['question_type'] ?? $question?->question_type;
        $options = array_key_exists('options', $data) ? $data['options'] : $question?->options;
        $useScoring = array_key_exists('use_in_match_score', $data)
            ? filter_var($data['use_in_match_score'], FILTER_VALIDATE_BOOLEAN)
            : (bool) ($question?->use_in_match_score ?? false);
        $typeChanged = $question && isset($data['question_type']) && $data['question_type'] !== $question->question_type;
        $points = array_key_exists('match_points', $data) ? $data['match_points'] : $question?->match_points;
        $answers = array_key_exists('match_answers', $data)
            ? $data['match_answers']
            : ($typeChanged ? null : $question?->match_answers);

        if ((int) $round->round_number !== 1) {
            if ($useScoring || filled($points) || filled($answers)) {
                $this->fail('use_in_match_score', 'The match score only applies to Round 1.');
            }

            return array_merge($data, [
                'use_in_match_score' => false,
                'match_points' => null,
                'match_answers' => null,
            ]);
        }

        if (! $round->program->use_match_score && $question?->use_in_match_score) {
            $pointsUnchanged = ! array_key_exists('match_points', $data)
                || (int) $data['match_points'] === (int) $question->match_points;
            $answersUnchanged = ! array_key_exists('match_answers', $data)
                || ($answers ?? null) == ($question->match_answers ?? null);

            if ($typeChanged || ! $pointsUnchanged || ! $answersUnchanged || ! $useScoring) {
                $this->fail('use_in_match_score', 'Turn on the match score for this program before changing a scored question.');
            }

            return array_merge($data, [
                'is_required' => true,
                'use_in_match_score' => true,
                'match_points' => $question->match_points,
                'match_answers' => $question->match_answers,
            ]);
        }

        if (! $useScoring) {
            return array_merge($data, [
                'use_in_match_score' => false,
                'match_points' => null,
                'match_answers' => null,
            ]);
        }

        if (! $round->program->use_match_score) {
            $this->fail('use_in_match_score', 'Turn on the match score for this program first.');
        }

        if (in_array($type, self::KNOCKOUT_TYPES, true)) {
            $this->fail('use_in_match_score', 'Knockout questions cannot be scored.');
        }

        $required = $data['is_required'] ?? $question?->is_required ?? false;
        if (! filter_var($required, FILTER_VALIDATE_BOOLEAN)) {
            $this->fail('is_required', 'Every question used in the match score must be required.');
        }

        if (! is_numeric($points) || (int) $points < 1 || (int) $points > 100 || (string) (int) $points !== (string) $points) {
            $this->fail('match_points', 'Match points must be an integer between 1 and 100.');
        }

        $normalizedAnswers = $this->validateMatchAnswers((string) $type, (array) ($options ?? []), $answers, (int) $points);

        $usedPoints = (int) RoundCustomQuestion::query()
            ->where('round_id', $round->id)
            ->where('use_in_match_score', true)
            ->when($question, fn ($query) => $query->where('id', '!=', $question->id))
            ->sum('match_points');
        $remaining = 100 - $usedPoints;
        if ($usedPoints + (int) $points > 100) {
            $this->fail('match_points', "Only {$remaining} points left. All scored questions together can't pass 100.");
        }

        return array_merge($data, [
            'is_required' => true,
            'use_in_match_score' => true,
            'match_points' => (int) $points,
            'match_answers' => $normalizedAnswers,
        ]);
    }

    public function score(Program $program, ProgramRound $round, array $answers): array
    {
        if (! $program->use_match_score || (int) $round->round_number !== 1) {
            return ['score' => null, 'match_score' => null, 'breakdown' => [], 'below_threshold' => false];
        }

        $questions = RoundCustomQuestion::query()
            ->where('round_id', $round->id)
            ->where('use_in_match_score', true)
            ->orderBy('display_order')
            ->get();

        if ($questions->isEmpty()) {
            return ['score' => null, 'match_score' => null, 'breakdown' => [], 'below_threshold' => false];
        }

        $answerMap = $this->answerMap($answers);
        $total = 0.0;
        $breakdown = [];

        foreach ($questions as $question) {
            $answer = $answerMap[$question->id] ?? [];
            $earned = $this->scoreAnswer($question, $answer);
            $points = (int) $question->match_points;
            $total += $earned;
            $breakdown[] = [
                'question_id' => (int) $question->id,
                'question_text' => $question->question_text,
                'match_points' => $points,
                'earned_points' => round($earned, 1),
                'status' => $earned >= $points ? 'matched' : 'not matched',
            ];
        }

        $score = round(min(100, max(0, $total)), 1);
        $limit = $program->match_score_limit;

        return [
            'score' => $score,
            'match_score' => $score,
            'breakdown' => $breakdown,
            'below_threshold' => $limit !== null && $score < (int) $limit,
        ];
    }

    public function scoreRequest(Program $program, array $answers): array
    {
        $round = $program->rounds()->where('round_number', 1)->first();

        return $round
            ? $this->score($program, $round, $answers)
            : ['score' => null, 'match_score' => null, 'breakdown' => [], 'below_threshold' => false];
    }

    public function recalculateApplication(ProgramApplication $application): array
    {
        $program = $application->program;
        $round = $program?->rounds()->where('round_number', 1)->first();

        if (! $program || ! $round || ! $program->use_match_score) {
            $result = ['score' => null, 'match_score' => null, 'breakdown' => [], 'below_threshold' => false];
        } else {
            $answers = ApplicationRoundResponse::query()
                ->where('application_id', $application->id)
                ->where('round_id', $round->id)
                ->get()
                ->mapWithKeys(fn (ApplicationRoundResponse $response) => [$response->question_id => [
                    'response' => $response->response,
                    'file_path' => $response->file_path,
                ]])
                ->all();
            $result = $this->score($program, $round, $answers);
        }

        $application->forceFill([
            'match_score' => $result['match_score'],
            'score_breakdown' => $result['breakdown'],
        ])->save();

        return $result;
    }

    public function recalculateRound(ProgramRound $round): void
    {
        if ((int) $round->round_number !== 1) {
            return;
        }

        ProgramApplication::query()
            ->where('program_id', $round->program_id)
            ->each(fn (ProgramApplication $application) => $this->recalculateApplication($application));
    }

    public function recalculateProgram(Program $program): void
    {
        ProgramApplication::query()
            ->where('program_id', $program->id)
            ->each(fn (ProgramApplication $application) => $this->recalculateApplication($application));
    }

    public function saveAnswer(ProgramApplication $application, ProgramRound $round, RoundCustomQuestion $question, ?string $response, ?string $filePath = null): ApplicationRoundResponse
    {
        $answer = ApplicationRoundResponse::updateOrCreate(
            [
                'application_id' => $application->id,
                'round_id' => $round->id,
                'question_id' => $question->id,
            ],
            ['response' => $response, 'file_path' => $filePath]
        );

        if ((int) $round->round_number === 1) {
            $this->recalculateApplication($application);
        }

        return $answer;
    }

    public function answerIsProvided(string $questionType, mixed $response, bool $hasFile = false): bool
    {
        if ($hasFile) {
            return true;
        }

        $type = $this->canonicalType($questionType);
        if ($type === 'file_upload') {
            return false;
        }

        $value = $this->decodeResponse($response, $type);

        if (in_array($type, ['checkbox', 'ranking'], true) && is_array($value)) {
            return collect($value)->contains(fn ($item) => $this->isPresent($item));
        }

        if ($type === 'location' && is_array($value)) {
            foreach (['region', 'country', 'specific_location', 'location'] as $part) {
                if ($this->isPresent($value[$part] ?? null)) {
                    return true;
                }
            }
            return false;
        }

        if ($type === 'matrix' && is_array($value)) {
            return collect($value)->contains(fn ($item) => $this->isPresent($item));
        }

        return $this->isPresent($value);
    }

    public function fileMatchesQuestionOptions(UploadedFile $file, array $options): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        $categories = [];
        $types = [];

        foreach ($options as $option) {
            $option = strtolower((string) $option);
            if (str_starts_with($option, 'type:')) {
                $types[] = substr($option, 5);
            } elseif (in_array($option, ['document', 'photo', 'video', 'beneficiary_list', 'other', 'photo_video'], true)) {
                $categories[] = $option;
            } elseif (in_array($option, ['pdf', 'doc', 'excel', 'powerpoint', 'csv', 'txt', 'rtf', 'image', 'svg', 'video', 'audio', 'zip', 'json', 'xml', 'html', 'code', 'cad', 'any'], true)) {
                $types[] = $option;
            }
        }

        if ($types && ! in_array('any', $types, true) && ! $this->fileHasType($extension, $mime, $types)) {
            return false;
        }

        if ($categories) {
            $isImage = str_starts_with($mime, 'image/')
                || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'svg'], true);
            $isVideo = str_starts_with($mime, 'video/')
                || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true);
            $isSpreadsheet = in_array($extension, ['csv', 'xls', 'xlsx'], true)
                || str_contains($mime, 'spreadsheet')
                || str_contains($mime, 'excel')
                || $mime === 'text/csv';
            $isDocument = in_array($extension, [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'rtf', 'json', 'xml',
            ], true)
                || in_array($mime, [
                    'application/pdf', 'application/msword', 'application/vnd.ms-excel',
                    'application/vnd.ms-powerpoint', 'text/plain', 'text/csv', 'application/rtf',
                ], true)
                || str_contains($mime, 'wordprocessingml')
                || str_contains($mime, 'spreadsheet')
                || str_contains($mime, 'presentation')
                || str_contains($mime, 'json')
                || str_contains($mime, 'xml');
            $categoryMatches = in_array('other', $categories, true)
                || (in_array('beneficiary_list', $categories, true) && $isSpreadsheet)
                || (in_array('photo', $categories, true) && $isImage)
                || (in_array('video', $categories, true) && $isVideo)
                || (in_array('photo_video', $categories, true) && ($isImage || $isVideo))
                || (in_array('document', $categories, true) && $isDocument);
            if (! $categoryMatches) {
                return false;
            }
        }

        return true;
    }

    private function validateMatchAnswers(string $questionType, array $options, mixed $answers, int $points): ?array
    {
        $type = $this->canonicalType($questionType);
        $optionValues = array_values(array_map('strval', $options));

        if (in_array($type, ['short_answer', 'long_text', 'paragraph', 'address'], true)) {
            if ($answers === null || $answers === []) {
                return null;
            }
            if (! is_array($answers) || ! isset($answers['keywords']) || ! is_array($answers['keywords'])) {
                $this->fail('match_answers', 'Short-text match answers must contain a keywords list or be null.');
            }
            return ['keywords' => array_values(array_filter(array_map(fn ($value) => trim((string) $value), $answers['keywords']), fn ($value) => $value !== ''))];
        }

        if (in_array($type, ['yes_no', 'true_false'], true)) {
            $allowed = $type === 'yes_no' ? ['yes', 'no'] : ['true', 'false'];
            $answers = is_array($answers) ? array_map(fn ($value) => mb_strtolower(trim((string) $value)), $answers) : [];
            if (! $answers || array_diff($answers, $allowed)) {
                $this->fail('match_answers', 'Choose at least one valid answer for this question.');
            }
            return array_values(array_unique($answers));
        }

        if (in_array($type, ['dropdown', 'multiple_choice', 'checkbox'], true)) {
            if (! is_array($answers)) {
                $this->fail('match_answers', 'Choose the answers that earn the points for every question used in the match score.');
            }
            $valid = [];
            $canonicalOptions = [];
            foreach ($optionValues as $optionValue) {
                $canonicalOptions[mb_strtolower(trim($optionValue))] = $optionValue;
            }
            foreach ($answers as $option => $value) {
                $canonicalOption = $canonicalOptions[mb_strtolower(trim((string) $option))] ?? null;
                if ($canonicalOption === null) {
                    continue;
                }
                if (! is_numeric($value) || (int) $value < 1 || (int) $value > $points) {
                    $this->fail('match_answers', 'Each selected answer must earn between 1 and the question’s match points.');
                }
                $valid[$canonicalOption] = (int) $value;
            }
            if (! $valid) {
                $this->fail('match_answers', 'Choose the answers that earn the points for every question used in the match score.');
            }
            return $valid;
        }

        if ($type === 'ranking') {
            if (count($optionValues) < 2) {
                $this->fail('match_answers', 'Add at least two ranking options before using this question in the match score.');
            }
            return ['order' => $optionValues];
        }

        if ($type === 'rating') {
            $min = is_array($answers) ? ($answers['min'] ?? null) : null;
            if (! is_numeric($min) || (int) $min < 1 || (int) $min > 5) {
                $this->fail('match_answers', 'Set a minimum rating from 1 to 5.');
            }
            return ['min' => (int) $min];
        }

        if (in_array($type, ['number', 'currency', 'percentage', 'linear_scale'], true)) {
            if (! is_array($answers) || (! array_key_exists('min', $answers) && ! array_key_exists('max', $answers))) {
                $this->fail('match_answers', 'Set at least one minimum or maximum value.');
            }
            foreach (['min', 'max'] as $bound) {
                if (array_key_exists($bound, $answers) && ! is_numeric($answers[$bound])) {
                    $this->fail('match_answers', 'Minimum and maximum values must be numeric.');
                }
            }
            if (isset($answers['min'], $answers['max']) && (float) $answers['min'] > (float) $answers['max']) {
                $this->fail('match_answers', 'The minimum cannot exceed the maximum.');
            }
            return array_intersect_key($answers, array_flip(['min', 'max']));
        }

        if ($type === 'phone') {
            $countries = $answers['countries'] ?? null;
            if (! is_array($countries) || ! $countries || array_filter($countries, fn ($country) => ! isset(self::PHONE_CODES[strtolower((string) $country)]))) {
                $this->fail('match_answers', 'Choose at least one valid lowercase ISO2 country code.');
            }
            return ['countries' => array_values(array_unique(array_map('strtolower', $countries)))];
        }

        if (in_array($type, ['email', 'url'], true)) {
            $domains = $answers['domains'] ?? [];
            if ($answers !== null && (! is_array($answers) || ! is_array($domains))) {
                $this->fail('match_answers', 'Domains must be provided as a list, or match answers may be null.');
            }
            foreach ($domains as $domain) {
                if (! is_string($domain) || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $this->fail('match_answers', 'Every accepted domain must be a valid hostname.');
                }
            }
            return $domains ? ['domains' => array_values(array_map('strtolower', $domains))] : null;
        }

        if (in_array($type, ['date', 'time', 'datetime'], true)) {
            if ($answers === null) {
                return null;
            }
            if (! is_array($answers) || (! array_key_exists('from', $answers) && ! array_key_exists('to', $answers))) {
                $this->fail('match_answers', 'Set at least one from or to value, or leave match answers null.');
            }
            $format = ['date' => '!Y-m-d', 'time' => '!H:i', 'datetime' => '!Y-m-d\\TH:i'][$type];
            foreach (['from', 'to'] as $bound) {
                if (isset($answers[$bound]) && ! $this->validDateValue((string) $answers[$bound], $format)) {
                    $this->fail('match_answers', "The {$bound} value has an invalid {$type} format.");
                }
            }
            if (isset($answers['from'], $answers['to']) && $answers['from'] > $answers['to']) {
                $this->fail('match_answers', 'The from value cannot be later than the to value.');
            }
            return array_intersect_key($answers, array_flip(['from', 'to']));
        }

        if ($type === 'location') {
            if (! is_array($answers)) {
                $this->fail('match_answers', 'Set accepted values for at least one selected location part.');
            }
            $valid = [];
            foreach ($answers as $part => $values) {
                if (! in_array((string) $part, $optionValues, true) || ! is_array($values)) {
                    continue;
                }
                $values = array_values(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn ($value) => $value !== ''));
                if ($values) {
                    $valid[(string) $part] = $values;
                }
            }
            if (! $valid) {
                $this->fail('match_answers', 'Set accepted values for at least one selected location part.');
            }
            return $valid;
        }

        if ($type === 'matrix') {
            if (! is_array($answers)) {
                $this->fail('match_answers', 'Set a best column for at least one matrix row.');
            }
            $rows = array_values(array_map(fn ($value) => substr((string) $value, 4), array_filter($optionValues, fn ($value) => str_starts_with($value, 'row:'))));
            $columns = array_values(array_map(fn ($value) => substr((string) $value, 4), array_filter($optionValues, fn ($value) => str_starts_with($value, 'col:'))));
            $valid = [];
            foreach ($answers as $row => $column) {
                if (in_array((string) $row, $rows, true) && in_array((string) $column, $columns, true)) {
                    $valid[(string) $row] = (string) $column;
                }
            }
            if (! $valid) {
                $this->fail('match_answers', 'Set a best column for at least one valid matrix row.');
            }
            return $valid;
        }

        if (in_array($type, ['file_upload', 'signature'], true)) {
            if ($answers !== null && $answers !== []) {
                $this->fail('match_answers', 'This question type does not use accepted match answers.');
            }
            return null;
        }

        $this->fail('match_answers', 'This question type cannot be used in the match score.');
    }

    private function scoreAnswer(RoundCustomQuestion $question, array $answer): float
    {
        $raw = $answer['response'] ?? null;
        $file = $answer['file_path'] ?? null;
        $type = $this->canonicalType($question->question_type);
        $value = $this->decodeResponse($raw, $type);
        $accepted = $question->match_answers ?? null;
        $points = (int) $question->match_points;

        if ($type === 'file_upload') {
            return $file || $value instanceof UploadedFile ? (float) $points : 0.0;
        }
        if ($type === 'signature') {
            return $this->isPresent($value) ? (float) $points : 0.0;
        }
        if (! $this->isPresent($value)) {
            return 0.0;
        }

        if (in_array($type, ['short_answer', 'long_text', 'paragraph', 'address'], true)) {
            $text = mb_strtolower(trim(is_scalar($value) ? (string) $value : json_encode($value)));
            $keywords = array_map(fn ($keyword) => mb_strtolower(trim((string) $keyword)), $accepted['keywords'] ?? []);
            return ! $keywords || collect($keywords)->contains(fn ($keyword) => $keyword !== '' && str_contains($text, $keyword))
                ? (float) $points
                : 0.0;
        }

        if (in_array($type, ['yes_no', 'true_false'], true)) {
            return in_array(mb_strtolower(trim((string) $value)), array_map('mb_strtolower', $accepted ?? []), true)
                ? (float) $points
                : 0.0;
        }

        if (in_array($type, ['dropdown', 'multiple_choice'], true)) {
            $selected = is_array($value) ? reset($value) : $value;
            return (float) $this->optionPoints($accepted, $selected);
        }

        if ($type === 'checkbox') {
            $selected = is_array($value) ? $value : [$value];
            $selected = array_unique(array_map(fn ($item) => mb_strtolower(trim((string) $item)), $selected));
            return (float) min($points, array_sum(array_map(fn ($item) => $this->optionPoints($accepted, $item), $selected)));
        }

        if ($type === 'ranking') {
            if (! is_array($value)) {
                return 0.0;
            }
            $order = $accepted['order'] ?? [];
            $count = count($order);
            if ($count < 2) {
                return 0.0;
            }

            $actualPositions = [];
            foreach ($value as $index => $item) {
                $key = mb_strtolower(trim((string) $item));
                if (! array_key_exists($key, $actualPositions)) {
                    $actualPositions[$key] = (int) $index;
                }
            }

            $distance = 0;
            foreach ($order as $expectedIndex => $expected) {
                $actualIndex = $actualPositions[mb_strtolower(trim((string) $expected))] ?? $count;
                $distance += abs($actualIndex - $expectedIndex);
            }

            $maximumDistance = intdiv($count * $count, 2);
            return $points * max(0, 1 - ($distance / $maximumDistance));
        }

        if ($type === 'rating') {
            return is_numeric($value) && (float) $value >= (float) ($accepted['min'] ?? 1)
                ? (float) $points
                : 0.0;
        }

        if (in_array($type, ['number', 'currency', 'percentage', 'linear_scale'], true)) {
            if (! is_numeric($value)) {
                return 0.0;
            }
            return $this->insideRange((float) $value, $accepted) ? (float) $points : 0.0;
        }

        if ($type === 'phone') {
            $digits = preg_replace('/\\D+/', '', (string) $value);
            if (! is_string($value) || ! str_starts_with(trim($value), '+') || $digits === '') {
                return 0.0;
            }
            foreach ($accepted['countries'] ?? [] as $country) {
                $code = self::PHONE_CODES[$country] ?? null;
                if ($code && str_starts_with($digits, $code)) {
                    return (float) $points;
                }
            }
            return 0.0;
        }

        if ($type === 'email') {
            if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return 0.0;
            }
            return $this->domainMatches(substr(strrchr($value, '@'), 1), $accepted['domains'] ?? [])
                ? (float) $points
                : 0.0;
        }

        if ($type === 'url') {
            if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
                return 0.0;
            }
            $host = parse_url($value, PHP_URL_HOST);
            return is_string($host) && $this->domainMatches($host, $accepted['domains'] ?? [])
                ? (float) $points
                : 0.0;
        }

        if (in_array($type, ['date', 'time', 'datetime'], true)) {
            $format = ['date' => '!Y-m-d', 'time' => '!H:i', 'datetime' => '!Y-m-d\\TH:i'][$type];
            if (! is_string($value) || ! $this->validDateValue($value, $format)) {
                return 0.0;
            }
            if (! $accepted) {
                return (float) $points;
            }
            return (! isset($accepted['from']) || $value >= $accepted['from'])
                && (! isset($accepted['to']) || $value <= $accepted['to'])
                ? (float) $points
                : 0.0;
        }

        if ($type === 'location') {
            $value = is_array($value) ? $value : [];
            if (isset($value['location']) && ! isset($value['specific_location'])) {
                $value['specific_location'] = $value['location'];
            }
            foreach ($accepted ?? [] as $part => $targets) {
                if (in_array(mb_strtolower(trim((string) ($value[$part] ?? ''))), array_map('mb_strtolower', $targets), true)) {
                    return (float) $points;
                }
            }
            return 0.0;
        }

        if ($type === 'matrix') {
            if (! is_array($value) || ! $accepted) {
                return 0.0;
            }
            $matched = 0;
            foreach ($accepted as $row => $column) {
                if (isset($value[$row]) && mb_strtolower(trim((string) $value[$row])) === mb_strtolower(trim((string) $column))) {
                    $matched++;
                }
            }
            return $points * ($matched / count($accepted));
        }

        return 0.0;
    }

    private function answerMap(array $answers): array
    {
        $map = [];
        foreach ($answers as $key => $answer) {
            if (is_array($answer) && array_key_exists('question_id', $answer)) {
                $map[(int) $answer['question_id']] = [
                    'response' => $answer['response'] ?? null,
                    'file_path' => $answer['file_path'] ?? null,
                ];
            } else {
                $map[(int) $key] = is_array($answer)
                    ? $answer
                    : ['response' => $answer, 'file_path' => null];
            }
        }
        return $map;
    }

    private function decodeResponse(mixed $response, string $type): mixed
    {
        if (! is_string($response) || ! in_array($type, ['checkbox', 'ranking', 'location', 'matrix'], true)) {
            return $response;
        }
        $decoded = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $response;
    }

    private function insideRange(float $value, array $range): bool
    {
        return (! isset($range['min']) || $value >= (float) $range['min'])
            && (! isset($range['max']) || $value <= (float) $range['max']);
    }

    private function optionPoints(array $pointsByOption, mixed $selected): int
    {
        $selected = mb_strtolower(trim((string) $selected));
        foreach ($pointsByOption as $option => $points) {
            if (mb_strtolower(trim((string) $option)) === $selected) {
                return (int) $points;
            }
        }

        return 0;
    }

    private function domainMatches(string $domain, array $domains): bool
    {
        if (! $domains) {
            return true;
        }
        $domain = strtolower(rtrim($domain, '.'));
        foreach ($domains as $target) {
            $target = strtolower(rtrim((string) $target, '.'));
            if ($domain === $target || str_ends_with($domain, '.'.$target)) {
                return true;
            }
        }
        return false;
    }

    private function validDateValue(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat($format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format(substr($format, 1)) === $value;
    }

    private function canonicalType(string $type): string
    {
        return self::TYPE_ALIASES[$type] ?? $type;
    }

    private function isPresent(mixed $value): bool
    {
        if ($value instanceof UploadedFile) {
            return $value->isValid();
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_bool($value) || is_numeric($value)) {
            return true;
        }
        return is_string($value) && trim($value) !== '';
    }

    private function fileHasType(string $extension, string $mime, array $types): bool
    {
        $extensions = [
            'pdf' => ['pdf'],
            'doc' => ['doc', 'docx'],
            'excel' => ['xls', 'xlsx'],
            'powerpoint' => ['ppt', 'pptx'],
            'csv' => ['csv'],
            'txt' => ['txt'],
            'rtf' => ['rtf'],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'svg'],
            'svg' => ['svg'],
            'video' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            'audio' => ['mp3', 'wav', 'aac', 'ogg', 'm4a'],
            'zip' => ['zip', 'rar', '7z', 'tar', 'gz'],
            'json' => ['json'],
            'xml' => ['xml'],
            'html' => ['html', 'htm'],
            'code' => ['js', 'ts', 'php', 'py', 'java', 'c', 'cpp', 'html', 'css', 'json', 'xml'],
            'cad' => ['dwg', 'dxf', 'step', 'stp', 'iges', 'igs'],
        ];

        return collect($types)->contains(function ($type) use ($extension, $mime, $extensions) {
            if (in_array($extension, $extensions[$type] ?? [], true)) {
                return true;
            }
            return match ($type) {
                'image' => str_starts_with($mime, 'image/'),
                'video' => str_starts_with($mime, 'video/'),
                'audio' => str_starts_with($mime, 'audio/'),
                'json' => str_contains($mime, 'json'),
                'xml' => str_contains($mime, 'xml'),
                'html' => str_contains($mime, 'html'),
                'pdf' => $mime === 'application/pdf',
                'any' => true,
                default => false,
            };
        });
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
