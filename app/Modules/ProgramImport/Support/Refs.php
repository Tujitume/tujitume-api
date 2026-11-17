<?php

namespace App\Modules\ProgramImport\Support;

/** Which registry key identifies a row of each sheet, and which entity type it becomes in the links table. */
class Refs
{
    /** sheet => [ref key(s), entity type, program scoped?] */
    public const MAP = [
        'program' => ['program_ref', 'program', false],
        'rounds' => ['round_ref', 'round', true],
        'questions' => ['question_ref', 'question', true],
        'fields' => ['field_ref', 'field', true],
        'applications' => ['application_ref', 'application', true],
        'winners' => ['application_ref', 'application', true],
        'suppliers' => ['supplier_ref', 'supplier', false],
        'milestones' => ['milestone_ref', 'milestone', true],
        'checkpoints' => ['checkpoint_ref', 'checkpoint', true],
    ];

    /** Default sheet type a single-type batch belongs to when no start point is chosen. */
    public const DEFAULT_START = [
        'program' => 'setup', 'rounds' => 'setup', 'questions' => 'setup', 'fields' => 'setup',
        'applications' => 'review', 'scores' => 'finalization', 'winners' => 'funding_setup',
        'suppliers' => 'disbursement', 'milestones' => 'disbursement', 'milestone_suppliers' => 'disbursement', 'disbursements' => 'disbursement',
        'checkpoints' => 'monitoring', 'submissions' => 'monitoring', 'reviewers' => 'review', 'site_visits' => 'monitoring',
    ];

    public static function entity(string $sheet): ?string
    {
        return self::MAP[$sheet][1] ?? null;
    }

    public static function key(string $sheet): ?string
    {
        return self::MAP[$sheet][0] ?? null;
    }

    public static function scoped(string $entity): bool
    {
        foreach (self::MAP as $m) {
            if ($m[1] === $entity) {
                return $m[2];
            }
        }

        return false;
    }

    /** The stored link reference. Program-scoped refs are prefixed so "a1" can repeat across programs. */
    public static function stored(string $entity, string $ref, ?int $programId): string
    {
        return self::scoped($entity) && $programId ? $programId.':'.$ref : $ref;
    }

    /** Idempotency reference for sheets without their own reference column. */
    public static function derived(string $sheet, array $v): ?string
    {
        return match ($sheet) {
            'milestone_suppliers' => ($v['milestone_ref'] ?? '').'|'.($v['supplier_ref'] ?? ''),
            'disbursements' => sha1(implode('|', [$v['milestone_ref'] ?? '', $v['amount'] ?? '', $v['disbursed_at'] ?? '', $v['payment_reference'] ?? ''])),
            'scores' => ($v['application_ref'] ?? '').'|'.($v['round_number'] ?? ''),
            'submissions' => $v['checkpoint_ref'] ?? null,
            'reviewers' => ($v['round_ref'] ?? '').'|'.strtolower((string) ($v['email'] ?? '')),
            'site_visits' => ($v['checkpoint_ref'] ?? '').'|'.($v['start_date'] ?? ''),
            default => null,
        };
    }
}
