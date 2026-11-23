<?php

namespace App\Modules\ProgramImport\Registry;

/**
 * Single source of truth for import types (sheets) and their fields.
 * Drives templates, heuristic mapping, validation, GET /types and the docs.
 * Field spec: key, label, type, required, synonyms, enum, default, target, rules.
 * Types: string, text, int, decimal, money, date, datetime, bool, enum, email, phone, url, list.
 */
class ImportTypeRegistry
{
    private static ?array $cache = null;

    /** Import order: parents before children (cross-sheet *_ref resolution). */
    public const ORDER = [
        'program', 'rounds', 'questions', 'fields', 'reviewers', 'applications', 'scores', 'winners',
        'suppliers', 'milestones', 'milestone_suppliers', 'disbursements', 'checkpoints', 'submissions', 'site_visits',
    ];

    public static function types(): array
    {
        return self::$cache ??= self::build();
    }

    public static function has(string $type): bool
    {
        return isset(self::types()[$type]);
    }

    public static function get(string $type): array
    {
        return self::types()[$type] ?? throw new \InvalidArgumentException("Unknown import type [$type]");
    }

    public static function fields(string $type): array
    {
        return self::get($type)['fields'];
    }

    public static function requiredKeys(string $type): array
    {
        return array_keys(array_filter(self::fields($type), fn ($f) => $f['required']));
    }

    /** Public shape for GET /types. */
    public static function describe(): array
    {
        $out = [];
        foreach (self::ORDER as $type) {
            $t = self::get($type);
            $out[] = ['type' => $type, 'label' => $t['label'], 'depends_on' => $t['depends_on'],
                'fields' => array_values($t['fields'])];
        }

        return $out;
    }

    private static function f(string $key, string $label, string $type = 'string', bool $required = false, array $o = []): array
    {
        return array_merge([
            'key' => $key, 'label' => $label, 'type' => $type, 'required' => $required,
            'synonyms' => [], 'enum' => null, 'default' => null, 'target' => $key, 'rules' => [],
        ], $o);
    }

    private static function type(string $label, array $dependsOn, array $fields): array
    {
        $map = [];
        foreach ($fields as $f) {
            $map[$f['key']] = $f;
        }

        return ['label' => $label, 'depends_on' => $dependsOn, 'fields' => $map];
    }

    private static function build(): array
    {
        $f = [self::class, 'f'];
        $t = [self::class, 'type'];
        $ref = fn (string $key, string $label, bool $req = false) => $f($key, $label, 'string', $req, ['synonyms' => [str_replace('_ref', ' id', $key), $key]]);

        return [
            'program' => $t('Program', [], [
                $f('program_ref', 'Program reference', 'string', false, ['synonyms' => ['program id', 'id']]),
                $f('program_title', 'Program title', 'string', true, ['synonyms' => ['title', 'name', 'program name'], 'rules' => ['max:255']]),
                $f('program_description', 'Description', 'text', false, ['synonyms' => ['summary', 'about'], 'rules' => ['max:500']]),
                $f('total_program_amount', 'Total program amount', 'money', true, ['synonyms' => ['total budget', 'budget', 'total amount', 'fund size']]),
                $f('funding_per_business', 'Funding per business', 'money', true, ['synonyms' => ['award amount', 'award size', 'per business', 'amount per awardee']]),
                $f('disbursement_type', 'Disbursement type', 'enum', true, ['enum' => ['supplier', 'hybrid', 'beneficiary'], 'synonyms' => ['payment route', 'disbursement']]),
                $f('program_type', 'Program type', 'enum', true, ['enum' => ['single_round', 'multi_round'], 'synonyms' => ['type', 'rounds type']]),
                $f('total_rounds', 'Total rounds', 'int', true, ['default' => 1, 'synonyms' => ['number of rounds', 'rounds']]),
                $f('program_industry', 'Industry (id or name)', 'string', true, ['target' => 'program_industry_id', 'synonyms' => ['industry', 'sector', 'program industry id']]),
                $f('currency', 'Currency', 'enum', true, ['enum' => ['KES', 'USD'], 'synonyms' => ['ccy']]),
                $f('application_start_date', 'Application start date', 'date', true, ['synonyms' => ['start date', 'opens', 'open date']]),
                $f('application_deadline', 'Application deadline', 'date', true, ['synonyms' => ['deadline', 'close date', 'closes', 'end date']]),
                $f('max_awardees', 'Max awardees', 'int', false, ['synonyms' => ['maximum awardees', 'slots']]),
                $f('require_kyc_verification_to_apply', 'Require KYC to apply', 'bool', false, ['default' => false]),
                $f('match_score_limit', 'Match score limit', 'int', false, ['rules' => ['min:1', 'max:100']]),
            ]),
            'rounds' => $t('Rounds', ['program'], [
                $ref('program_ref', 'Program reference'),
                $f('round_ref', 'Round reference', 'string', false),
                $f('round_number', 'Round number', 'int', true, ['synonyms' => ['round', 'number', 'no']]),
                $f('round_name', 'Round name', 'string', true, ['synonyms' => ['name', 'title', 'stage name']]),
                $f('open_date', 'Open date', 'date'), $f('close_date', 'Close date', 'date'),
                $f('review_period_end', 'Review period end', 'date', false, ['synonyms' => ['review end', 'review deadline']]),
                $f('announcement_date', 'Announcement date', 'date'),
                $f('rubric_mode', 'Rubric mode', 'enum', false, ['enum' => ['weighted', 'simple_total', 'pass_fail'], 'default' => 'simple_total']),
                $f('scoring_criteria', 'Scoring criteria (name:weight:max, ...)', 'list', false, ['synonyms' => ['criteria', 'rubric']]),
                $f('knockout_questions', 'Knockout questions', 'list'),
                $f('required_documents', 'Required documents', 'list', false, ['synonyms' => ['documents']]),
                $f('assignment_type', 'Assignment type', 'enum', false, ['enum' => ['owner_only', 'internal', 'external', 'both'], 'default' => 'owner_only']),
                $f('assignment_method', 'Assignment method', 'enum', false, ['enum' => ['manual', 'round_robin', 'load_balanced'], 'default' => 'manual']),
                $f('advancement_mode', 'Advancement mode', 'enum', false, ['enum' => ['manual', 'score_threshold', 'fixed_quota'], 'default' => 'manual']),
                $f('score_threshold', 'Score threshold', 'decimal', false, ['rules' => ['min:0', 'max:100']]),
                $f('max_advancing', 'Max advancing', 'int'),
                $f('tie_breaker_rule', 'Tie breaker', 'enum', false, ['enum' => ['allow_over_cap', 'secondary_metric', 'manual']]),
            ]),
            'questions' => $t('Round questions', ['rounds'], [
                $ref('round_ref', 'Round reference', true),
                $f('question_ref', 'Question reference', 'string', true, ['synonyms' => ['question id', 'id', 'code']]),
                $f('question_text', 'Question', 'text', true, ['synonyms' => ['question', 'label', 'text']]),
                $f('question_type', 'Type', 'enum', true, ['enum' => \App\Modules\ProgramImport\Importers\QuestionImporter::APP_TYPES, 'aliases' => \App\Modules\ProgramImport\Importers\QuestionImporter::ALIASES, 'synonyms' => ['type']]),
                $f('is_required', 'Required', 'bool', false, ['default' => false, 'synonyms' => ['required']]),
                $f('options', 'Options', 'list', false, ['synonyms' => ['choices']]),
                $f('help_text', 'Help text', 'text'),
            ]),
            'fields' => $t('Custom fields', ['program'], [
                $ref('program_ref', 'Program reference'),
                $f('field_ref', 'Field reference', 'string', false, ['synonyms' => ['field id', 'id', 'code', 'key']]),
                $f('label', 'Label', 'string', true, ['synonyms' => ['field', 'field name', 'question']]),
                $f('audience', 'Audience', 'enum', false, ['enum' => ['application', 'info'], 'default' => 'application']),
                $f('type', 'Type', 'enum', true, ['enum' => ['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multi_select', 'file', 'url']]),
                $f('is_required', 'Required', 'bool', false, ['default' => false, 'synonyms' => ['required']]),
                $f('options', 'Options', 'list', false, ['synonyms' => ['choices']]),
                $f('help_text', 'Help text', 'text'),
                $f('scoring_match_type', 'Suggested match type (never applied automatically)', 'enum', false, ['enum' => ['in_set', 'range', 'equals', 'provided']]),
                $f('scoring_weight', 'Suggested weight', 'decimal'),
                $f('scoring_target', 'Suggested target (list, or min..max, or true/false)', 'text'),
                $f('info_value', 'Value (info fields only)', 'text', false, ['synonyms' => ['value', 'answer']]),
            ]),
            'reviewers' => $t('Business Coaches (roster only)', ['rounds'], [
                $ref('round_ref', 'Round reference', true),
                $f('email', 'Email of an existing account', 'email', true, ['synonyms' => ['coach email', 'reviewer email', 'e-mail']]),
                $f('reviewer_type', 'Type', 'enum', false, ['enum' => ['internal', 'external'], 'default' => 'external']),
                $f('expertise_tags', 'Expertise', 'list', false, ['synonyms' => ['tags', 'skills']]),
                $f('max_apps_assigned', 'Max applications', 'int'),
            ]),
            'applications' => $t('Applications', ['program', 'rounds'], [
                $f('application_ref', 'Application reference', 'string', true, ['synonyms' => ['application id', 'id', 'response id', 'timestamp']]),
                $ref('program_ref', 'Program reference'),
                $f('round_number', 'Current round number', 'int', false, ['default' => 1]),
                $f('startup_name', 'Business name', 'string', true, ['synonyms' => ['business name', 'company', 'organization', 'startup']]),
                $f('contact_person_name', 'Contact person', 'string', false, ['synonyms' => ['contact', 'applicant name', 'full name', 'name']]),
                $f('email', 'Applicant email', 'email', false, ['synonyms' => ['email address', 'contact email', 'e-mail']]),
                $f('phone', 'Phone', 'phone', false, ['synonyms' => ['mobile', 'telephone', 'phone number']]),
                $f('sector', 'Sector', 'string', true, ['synonyms' => ['industry', 'category']]),
                $f('headquarters_location', 'Headquarters location', 'string', true, ['synonyms' => ['location', 'city', 'country', 'hq']]),
                $f('total_amount_requested', 'Amount requested', 'money', true, ['synonyms' => ['amount', 'request', 'funding requested', 'ask']]),
                $f('stage', 'Stage', 'string'), $f('revenue_last_12_months', 'Revenue (12 months)', 'money', false, ['synonyms' => ['revenue', 'annual revenue']]),
                $f('traction_kpis', 'Traction', 'text', false, ['synonyms' => ['traction', 'kpis']]),
                $f('social_impact_areas', 'Social impact areas', 'list', false, ['synonyms' => ['impact areas', 'impact']]),
                $f('pitch_deck_file', 'Pitch deck (link or file name in the attachments ZIP)', 'link_or_file', false, ['synonyms' => ['pitch deck', 'deck']]),
                $f('pitch_video', 'Pitch video link', 'url', false, ['synonyms' => ['video']]),
                $f('business_plan_file', 'Business plan (link or file name in the attachments ZIP)', 'link_or_file', false, ['synonyms' => ['business plan']]),
                $f('match_score', 'Match score (snapshot)', 'decimal', false, ['rules' => ['min:0', 'max:100']]),
                $f('outcome', 'Outcome', 'enum', false, ['enum' => ['pending', 'rejected'], 'synonyms' => ['result', 'decision', 'status']]),
                $f('knockout_result', 'Knockout result', 'enum', false, ['enum' => ['passed', 'failed'], 'synonyms' => ['knockout', 'eligibility']]),
            ]),
            'scores' => $t('Scores', ['applications'], [
                $ref('application_ref', 'Application reference', true),
                $f('round_number', 'Round number', 'int', false, ['default' => null]),
                $f('total_score', 'Total score', 'decimal', false, ['synonyms' => ['score', 'total', 'average score']]),
                $f('criterion', 'Criterion', 'string'),
                $f('criterion_score', 'Criterion score', 'decimal'),
                $f('comment', 'Comment', 'text'),
            ]),
            'winners' => $t('Winners (awardees)', ['program'], [
                $ref('application_ref', 'Application reference', false),
                $ref('program_ref', 'Program reference'),
                $f('startup_name', 'Business name', 'string', false),
                $f('email', 'Applicant email', 'email'),
                $f('awarded_amount', 'Awarded amount', 'money', true, ['synonyms' => ['amount', 'award', 'award value']]),
                $f('awarded_at', 'Award date', 'date', false, ['synonyms' => ['date awarded', 'award date']]),
                $f('sector', 'Sector', 'string'), $f('headquarters_location', 'Headquarters location', 'string'),
                $f('planning_mode', 'Planning mode', 'enum', false, ['enum' => ['locked', 'hybrid'], 'default' => 'locked']),
            ]),
            'suppliers' => $t('Suppliers', [], [
                $f('supplier_ref', 'Supplier reference', 'string', true),
                $f('legal_name', 'Legal name', 'string', true, ['synonyms' => ['name', 'supplier', 'vendor']]),
                $f('email', 'Email', 'email'), $f('phone', 'Phone', 'phone'),
                $f('payment_method', 'Payment method', 'enum', false, ['enum' => ['mpesa_mobile', 'mpesa_paybill', 'mpesa_till', 'bank_transfer', 'other']]),
                $f('payment_details', 'Payment details', 'text'),
            ]),
            'milestones' => $t('Milestones', ['winners'], [
                $ref('application_ref', 'Application reference', true),
                $f('milestone_ref', 'Milestone reference', 'string', true),
                $f('sequence_order', 'Sequence', 'int', true, ['synonyms' => ['order', 'no', 'number']]),
                $f('title', 'Title', 'string', true, ['synonyms' => ['name', 'milestone']]),
                $f('description', 'Description', 'text', false, ['rules' => ['max:1000']]),
                $f('amount', 'Amount', 'money', true),
                $f('status', 'Status', 'enum', false, ['enum' => ['pending', 'approved', 'completion_approved'], 'default' => 'pending', 'synonyms' => ['state']]),
                $f('fund_release_status', 'Fund release status', 'enum', false, ['enum' => ['locked', 'approved', 'released']]),
                $f('due_date', 'Due / estimated completion date', 'date', false, ['target' => 'estimated_completion_date']),
                $f('completed_at', 'Completed on', 'date'),
                $f('mprv_required', 'Needs a payment-release verification (MPRV)', 'bool', false, ['default' => false]),
                $f('mid_milestone_required', 'Needs a mid-milestone verification', 'bool', false, ['default' => false]),
                $f('final_approval_required', 'Needs a final approval', 'bool', false, ['default' => false]),
            ]),
            'milestone_suppliers' => $t('Milestone supplier assignments', ['milestones', 'suppliers'], [
                $ref('milestone_ref', 'Milestone reference', true),
                $ref('supplier_ref', 'Supplier reference', true),
                $f('amount', 'Amount', 'money', true),
            ]),
            'disbursements' => $t('Disbursements (record only)', ['milestones'], [
                $ref('milestone_ref', 'Milestone reference', true),
                $f('amount', 'Amount', 'money', true),
                $f('disbursed_at', 'Paid on', 'date', true, ['synonyms' => ['date paid', 'payment date']]),
                $f('payment_reference', 'Payment reference', 'string', false, ['synonyms' => ['reference', 'receipt no', 'transaction id']]),
                $f('payment_method', 'Payment method', 'enum', false, ['enum' => ['mpesa_paybill', 'mpesa_mobile', 'mpesa_till', 'bank_transfer', 'pochi', 'other'], 'default' => 'other']),
                $ref('supplier_ref', 'Supplier reference'),
                $f('recipient_type', 'Recipient type', 'enum', false, ['enum' => ['supplier', 'business_owner'], 'default' => 'supplier']),
            ]),
            'checkpoints' => $t('M&E checkpoints', ['winners'], [
                $ref('application_ref', 'Application reference', true),
                $f('checkpoint_ref', 'Checkpoint reference', 'string', true),
                $f('checkpoint_name', 'Name', 'string', true, ['synonyms' => ['name', 'checkpoint']]),
                $f('type', 'Type', 'enum', true, ['enum' => ['monitoring', 'reporting', 'meeting', 'site_visit', 'milestone_review', 'financial_audit', 'kpi_assessment', 'impact_evaluation', 'disbursement_approval', 'closure_review']]),
                $f('due_date', 'Due date', 'date'),
                $f('requirement', 'Requirement', 'text'),
                $f('kpis_to_track', 'KPIs to track', 'list'),
                $f('evidence_required', 'Evidence required', 'list'),
                $f('require_site_visit', 'Requires site visit', 'bool', false, ['default' => false]),
            ]),
            'site_visits' => $t('Site visits already done (record only)', ['checkpoints'], [
                $ref('checkpoint_ref', 'Checkpoint reference', true),
                $f('reviewer_email', 'Visitor email (existing account, optional)', 'email'),
                $f('inspector', 'Inspector name', 'string', false, ['synonyms' => ['visitor', 'officer']]),
                $f('start_date', 'Visit date', 'date', true, ['synonyms' => ['date', 'visited on']]),
                $f('location', 'Location', 'string'),
                $f('objective', 'Objective', 'text'),
                $f('observed_actions', 'Observed actions', 'text'),
                $f('evidence_found', 'Evidence found', 'text'),
                $f('risk_notes', 'Risk notes', 'text'),
                $f('recommendation_notes', 'Recommendation', 'text'),
                $f('visit_comments', 'Comments', 'text'),
            ]),
            'submissions' => $t('Checkpoint submissions (historic)', ['checkpoints'], [
                $ref('checkpoint_ref', 'Checkpoint reference', true),
                $f('written_report', 'Written report', 'text'),
                $f('kpi_actual_values', 'KPI actual values (kpi:value, ...)', 'list'),
                $f('submitted_at', 'Submitted on', 'date'),
            ]),
        ];
    }
}
