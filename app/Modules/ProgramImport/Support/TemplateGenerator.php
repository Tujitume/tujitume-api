<?php

namespace App\Modules\ProgramImport\Support;

use App\Models\ProgramIndustry;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Downloadable templates generated from the registry. Each start point gets only the sheets it uses, filled with
 * example rows whose references link across sheets (program → rounds → questions → applications → awardees → milestones…).
 * Fixed-choice columns get a dropdown; the industry column lists the real industries on Tujitume.
 * Headers are the registry keys, so the columns match automatically.
 */
class TemplateGenerator
{
    private const MAX_ROWS = 500;

    public static function exists(string $name): bool
    {
        return in_array($name, StartPointCatalog::POINTS, true) || ImportTypeRegistry::has($name);
    }

    /** @return string path of a temp file */
    public function build(string $name, string $format): string
    {
        $isPoint = in_array($name, StartPointCatalog::POINTS, true);
        if ($isPoint && $format === 'csv') {
            throw new \InvalidArgumentException('bundle_requires_xlsx');
        }
        $sheets = $isPoint ? $this->ordered(StartPointCatalog::sheets($name)) : [$name];
        $examples = $this->examples($isPoint ? $name : null, $sheets);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Instructions');
        $this->instructions($book->getActiveSheet(), $name, $isPoint, $sheets);

        $lists = $book->createSheet();
        $lists->setTitle('Lists');
        $listCol = 1;
        $industries = $this->industries();
        $listRef = function (string $title, array $values) use ($lists, &$listCol): string {
            $lists->setCellValue([$listCol, 1], $title);
            $lists->getStyle([$listCol, 1])->getFont()->setBold(true);
            foreach (array_values($values) as $i => $v) {
                $lists->setCellValue([$listCol, $i + 2], $v);
            }
            $lists->getColumnDimensionByColumn($listCol)->setAutoSize(true);
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($listCol);
            $listCol++;

            return 'Lists!$'.$letter.'$2:$'.$letter.'$'.(count($values) + 1);
        };

        foreach ($sheets as $type) {
            $ws = $book->createSheet();
            $ws->setTitle($type);
            $rows = $examples[$type] ?? [];
            $col = 1;
            foreach (ImportTypeRegistry::fields($type) as $key => $f) {
                $ws->setCellValue([$col, 1], $key);
                $style = $ws->getStyle([$col, 1]);
                $style->getFont()->setBold(true)->getColor()->setARGB($f['required'] ? 'FFFFFFFF' : 'FF1F2937');
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($f['required'] ? 'FF0F766E' : 'FFE5E7EB');
                $ws->getComment([$col, 1])->getText()->createTextRun($f['label'].($f['required'] ? ' (required)' : ' (optional)'));
                foreach ($rows as $i => $row) {
                    if (array_key_exists($key, $row) && $row[$key] !== null) {
                        $ws->setCellValueExplicit([$col, $i + 2], (string) $row[$key], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }
                $choices = $key === 'program_industry' ? $industries : (($f['type'] === 'enum' && $f['enum']) ? $f['enum'] : null);
                if ($choices) {
                    $inline = '"'.implode(',', $choices).'"';
                    $formula = mb_strlen($inline) <= 250 && ! array_filter($choices, fn ($c) => str_contains($c, ',')) ? $inline : $listRef($f['label'], $choices);
                    $dv = new DataValidation;
                    $dv->setType(DataValidation::TYPE_LIST)->setAllowBlank(! $f['required'])->setShowDropDown(true)->setShowErrorMessage(true)
                        ->setErrorTitle('Pick from the list')->setError('Choose one of the values in the list.')->setFormula1($formula);
                    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $ws->setDataValidation("{$letter}2:{$letter}".self::MAX_ROWS, $dv);
                }
                $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
                $col++;
            }
            if ($type === 'applications' && in_array('questions', $sheets, true)) {
                // One column per question: the column name is the question text, so answers link automatically
                foreach ($examples['questions'] as $q) {
                    $ws->setCellValue([$col, 1], $q['question_text']);
                    $style = $ws->getStyle([$col, 1]);
                    $style->getFont()->setBold(true);
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDBEAFE');
                    $ws->getComment([$col, 1])->getText()->createTextRun("Answer to question {$q['question_ref']} ({$q['round_ref']}). The column name must be the question text.");
                    foreach ($rows as $i => $row) {
                        isset($row[$q['question_ref']]) && $ws->setCellValueExplicit([$col, $i + 2], (string) $row[$q['question_ref']], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                    $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
                    $col++;
                }
            }
            $ws->freezePane('A2');
        }
        if ($listCol === 1) {
            $book->removeSheetByIndex($book->getIndex($lists));
        }
        $path = tempnam(sys_get_temp_dir(), 'pit');
        if ($format === 'csv') {
            $index = $book->getIndex($book->getSheetByName($name));
            $book->setActiveSheetIndex($index);
            $w = new Csv($book);
            $w->setSheetIndex($index);
            $w->save($path);
        } else {
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->save($path);
        }

        return $path;
    }

    /** Sheets in the order they are imported, so the template reads like the program's life. */
    private function ordered(array $sheets): array
    {
        return array_values(array_filter(ImportTypeRegistry::ORDER, fn ($s) => in_array($s, $sheets, true)));
    }

    private function industries(): array
    {
        try {
            return ProgramIndustry::orderBy('name')->pluck('name')->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function instructions($ws, string $name, bool $isPoint, array $sheets): void
    {
        $rows = [['How to fill this Tujitume import template'], [],
            ['1. Each tab is one kind of data. Replace the example rows with your own (keep the first row with the column names as it is).'],
            ['2. Green columns are required. Grey columns are optional: leave them empty if you do not have that information.'],
            ['3. Columns with a dropdown only accept the values in the list. Industry must be one of the industries on Tujitume (see the list).'],
            ['4. References (program_ref, round_ref, application_ref, milestone_ref…) are short codes you choose. They link rows across tabs, e.g. a question with round_ref R1 belongs to the round whose round_ref is R1.'],
            ['5. Dates: YYYY-MM-DD (2026-03-31). Amounts: digits only, e.g. 50000 (never "50k"). Lists: separate items with ;'],
            ['6. Hover a column name to see what it means.'], [],
        ];
        if ($isPoint) {
            $p = StartPointCatalog::get($name);
            $rows[] = ['This template is for: '.$p['label']];
            $rows[] = [$p['summary']];
            $rows[] = ['Required tabs: '.implode(', ', array_map(fn ($s) => ImportTypeRegistry::get($s)['label'], $p['required']))];
            $rows[] = ['Optional tabs: '.($p['optional'] ? implode(', ', array_map(fn ($s) => ImportTypeRegistry::get($s)['label'], $p['optional'])) : 'none')];
            $rows[] = ['After the import: '.implode(' → ', $p['next_steps'])];
            $rows[] = [];
        }
        $rows[] = ['Tab', 'Column', 'What it is', 'Type', 'Required', 'Allowed values'];
        $header = count($rows);
        foreach ($sheets as $type) {
            foreach (ImportTypeRegistry::fields($type) as $key => $f) {
                $allowed = $key === 'program_industry' ? 'See the Lists tab' : ($f['enum'] ? implode(', ', $f['enum']) : '');
                $rows[] = [$type, $key, $f['label'], $f['type'], $f['required'] ? 'yes' : 'no', $allowed];
            }
        }
        $ws->fromArray($rows, null, 'A1', true);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->getStyle("A{$header}:F{$header}")->getFont()->setBold(true);
        $ws->getColumnDimension('A')->setWidth(22);
        $ws->getColumnDimension('B')->setWidth(32);
        $ws->getColumnDimension('C')->setWidth(44);
        $ws->getColumnDimension('F')->setWidth(60);
    }

    /** Example rows. References link across tabs; the applications show how many reached each round and who won. */
    private function examples(?string $startPoint, array $sheets = []): array
    {
        $industry = $this->industries()[0] ?? 'Agriculture';
        $currency = config('app.currency', 'KES');
        $year = (int) date('Y');
        $past = $startPoint && $startPoint !== 'setup';
        $d = fn (string $md, int $offset = 0) => ($past ? $year - 1 + $offset : $year + 1 + $offset).'-'.$md;
        $rounds = in_array($startPoint, ['review', 'finalization'], true) ? 2 : 3;

        $ex['program'] = [[
            'program_ref' => 'PRG-1', 'program_title' => 'Youth Agribusiness Fund', 'program_description' => 'Grants for youth-led agribusinesses.',
            'total_program_amount' => 1000000, 'funding_per_business' => 100000, 'disbursement_type' => 'supplier', 'program_type' => 'multi_round',
            'total_rounds' => $rounds, 'program_industry' => $industry, 'currency' => $currency, 'application_start_date' => $d('01-10'),
            'application_deadline' => $d('02-10'), 'max_awardees' => 10, 'require_kyc_verification_to_apply' => 'no',
        ]];
        $names = [1 => 'Application', 2 => 'Pitch', 3 => 'Final interview'];
        $ex['rounds'] = [];
        for ($n = 1; $n <= $rounds; $n++) {
            $ex['rounds'][] = ['program_ref' => 'PRG-1', 'round_ref' => "R$n", 'round_number' => $n, 'round_name' => $names[$n],
                'open_date' => $d(sprintf('%02d-01', $n * 2)), 'close_date' => $d(sprintf('%02d-20', $n * 2)), 'review_period_end' => $d(sprintf('%02d-28', $n * 2)),
                'rubric_mode' => 'weighted', 'scoring_criteria' => 'Market:40:10; Team:30:10; Traction:30:10', 'advancement_mode' => 'manual'];
        }
        $ex['questions'] = [
            ['round_ref' => 'R1', 'question_ref' => 'Q1', 'question_text' => 'Your company email', 'question_type' => 'email', 'is_required' => 'yes'],
            ['round_ref' => 'R1', 'question_ref' => 'Q2', 'question_text' => 'What problem does your business solve?', 'question_type' => 'long_text', 'is_required' => 'yes', 'help_text' => '500 characters max'],
            ['round_ref' => 'R1', 'question_ref' => 'Q3', 'question_text' => 'Which value chain are you in?', 'question_type' => 'multiple_choice', 'is_required' => 'yes', 'options' => 'Production; Processing; Distribution'],
            ['round_ref' => 'R1', 'question_ref' => 'Q4', 'question_text' => 'Upload your pitch deck', 'question_type' => 'file_upload', 'is_required' => 'no'],
            ['round_ref' => 'R2', 'question_ref' => 'Q5', 'question_text' => 'Pitch video link', 'question_type' => 'video_link', 'is_required' => 'yes'],
            ['round_ref' => 'R2', 'question_ref' => 'Q6', 'question_text' => 'Signature of the founder', 'question_type' => 'signature', 'is_required' => 'yes'],
        ];
        $ex['fields'] = [['program_ref' => 'PRG-1', 'field_ref' => 'F1', 'label' => 'Number of employees', 'type' => 'number', 'is_required' => 'no']];
        // A Business Coach must already have an eligible Tujitume account. A made-up example email would make an
        // otherwise untouched template fail at commit, so keep this optional roster tab ready but intentionally empty.
        $ex['reviewers'] = [];

        // Applications: everyone applied in round 1, some reached later rounds; the awardees are the ones still in the last round
        $people = [['Green Farms', 'Amina Otieno', 'Agriculture', 'Kisumu'], ['Mavuno Foods', 'Brian Mwangi', 'Food processing', 'Nakuru'],
            ['Shamba Tech', 'Cynthia Achieng', 'Agritech', 'Nairobi'], ['Maziwa Bora', 'David Kiprop', 'Dairy', 'Eldoret'],
            ['Mboga Fresh', 'Esther Wanjiru', 'Horticulture', 'Kiambu'], ['Asali Gold', 'Felix Mutua', 'Apiculture', 'Machakos']];
        $reached = $rounds === 3 ? [1, 1, 2, 2, 3, 3] : [1, 1, 1, 2, 2, 2];
        $ex['applications'] = [];
        foreach ($people as $i => [$biz, $contact, $sector, $hq]) {
            $slug = strtolower(str_replace(' ', '', $biz));
            $ex['applications'][] = ['application_ref' => 'APP-'.($i + 1), 'program_ref' => 'PRG-1', 'round_number' => $reached[$i], 'startup_name' => $biz,
                'contact_person_name' => $contact, 'email' => "$slug@example.com", 'phone' => '0712 345 67'.$i, 'sector' => $sector, 'headquarters_location' => $hq,
                'total_amount_requested' => 100000, 'stage' => 'early_revenue', 'Q1' => "$slug@example.com", 'Q2' => 'We reduce post-harvest losses for smallholder farmers.',
                'Q3' => 'Processing'];
        }
        // Scores belong to the round being decided, one row per scoring criterion of that round
        $ex['scores'] = [];
        foreach (array_filter($ex['applications'], fn ($a) => $a['round_number'] === $rounds) as $a) {
            foreach (['Market' => 7, 'Team' => 8, 'Traction' => 6] as $criterion => $score) {
                $ex['scores'][] = ['application_ref' => $a['application_ref'], 'round_number' => $rounds, 'criterion' => $criterion,
                    'criterion_score' => $score + (int) substr($a['application_ref'], 4) % 3, 'comment' => $criterion === 'Team' ? 'Strong team' : null];
            }
        }
        $winners = array_slice($ex['applications'], 4);
        $ex['winners'] = array_map(fn ($a) => ['application_ref' => $a['application_ref'], 'program_ref' => 'PRG-1', 'startup_name' => $a['startup_name'], 'email' => $a['email'],
            'awarded_amount' => 100000, 'awarded_at' => $d('09-01'), 'sector' => $a['sector'], 'headquarters_location' => $a['headquarters_location']], $winners);
        $ex['suppliers'] = [['supplier_ref' => 'SUP-1', 'legal_name' => 'Agro Inputs Ltd', 'email' => 'sales@agroinputs.example.com', 'payment_method' => 'mpesa_paybill', 'payment_details' => 'Paybill 123456']];
        $ex['milestones'] = [];
        $ex['milestone_suppliers'] = [];
        $ex['disbursements'] = [];
        $ex['checkpoints'] = [];
        $ex['submissions'] = [];
        $ex['site_visits'] = [];
        $paid = in_array($startPoint, ['disbursement', 'monitoring'], true);
        foreach ($winners as $w => $a) {
            $ref = $a['application_ref'];
            foreach ([[1, 'Buy equipment', 60000], [2, 'Marketing launch', 40000]] as [$seq, $title, $amount]) {
                $mref = "M-$ref-$seq";
                $released = $paid && $seq === 1;
                $ex['milestones'][] = ['application_ref' => $ref, 'milestone_ref' => $mref, 'sequence_order' => $seq, 'title' => $title, 'amount' => $amount,
                    'status' => $released && $startPoint === 'monitoring' ? 'completion_approved' : 'pending', 'fund_release_status' => $released ? 'released' : ($seq === 1 ? 'approved' : 'locked'),
                    'due_date' => $d(sprintf('%02d-28', 9 + $seq))];
                if ($seq === 1) {
                    $ex['milestone_suppliers'][] = ['milestone_ref' => $mref, 'supplier_ref' => 'SUP-1', 'amount' => $amount];
                }
                if ($released) {
                    $withSupplier = in_array('suppliers', $sheets, true);
                    $ex['disbursements'][] = ['milestone_ref' => $mref, 'amount' => $amount, 'disbursed_at' => $d('10-15'), 'payment_reference' => 'QX'.($w + 1).'ABC123',
                        'payment_method' => 'mpesa_paybill', 'supplier_ref' => $withSupplier ? 'SUP-1' : null, 'recipient_type' => $withSupplier ? 'supplier' : 'business_owner'];
                }
            }
            $cref = "CP-$ref";
            $ex['checkpoints'][] = ['application_ref' => $ref, 'checkpoint_ref' => $cref, 'checkpoint_name' => 'Quarter 1 report', 'type' => 'reporting',
                'due_date' => $d('12-31'), 'requirement' => 'Share sales and jobs created.', 'kpis_to_track' => 'Revenue; Jobs created', 'evidence_required' => 'Sales records'];
            $ex['submissions'][] = ['checkpoint_ref' => $cref, 'written_report' => 'Sales grew 20%.', 'kpi_actual_values' => 'Revenue:120000; Jobs created:4', 'submitted_at' => $d('12-20')];
            $ex['site_visits'][] = ['checkpoint_ref' => $cref, 'inspector' => 'Jane Field', 'start_date' => $d('12-10'), 'location' => $a['headquarters_location'], 'objective' => 'Check equipment is in use'];
        }

        return $ex;
    }
}
