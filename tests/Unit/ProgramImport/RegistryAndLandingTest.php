<?php

use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;

it('has every type in dependency order with required keys', function () {
    foreach (ImportTypeRegistry::ORDER as $type) {
        expect(ImportTypeRegistry::has($type))->toBeTrue();
        foreach (ImportTypeRegistry::get($type)['depends_on'] as $dep) {
            expect(array_search($dep, ImportTypeRegistry::ORDER))->toBeLessThan(array_search($type, ImportTypeRegistry::ORDER));
        }
    }
    expect(ImportTypeRegistry::requiredKeys('program'))->toContain('program_title', 'currency');
});

it('only uses enum values the real tables accept', function () {
    expect(ImportTypeRegistry::fields('program')['disbursement_type']['enum'])->toBe(['supplier', 'hybrid', 'beneficiary'])
        ->and(ImportTypeRegistry::fields('winners')['planning_mode']['enum'])->toBe(['locked', 'hybrid'])
        ->and(ImportTypeRegistry::fields('milestones')['fund_release_status']['enum'])->not->toContain('pending');
});

it('defines every start point with required sheets that exist in the registry', function () {
    foreach (StartPointCatalog::POINTS as $sp) {
        foreach (StartPointCatalog::sheets($sp) as $sheet) {
            expect(ImportTypeRegistry::has($sheet))->toBeTrue("$sp -> $sheet");
        }
        expect(StartPointCatalog::landing($sp)->startPoint())->toBe($sp);
    }
});

it('lands review rounds so accept/advance/finalize can run', function () {
    $l = StartPointCatalog::landing('review');
    expect($l->program()['status'])->toBe('under_review')
        ->and($l->roundStatus(1, 2))->toBe('finalized')
        ->and($l->roundStatus(2, 2))->toBe('published')       // advanceManual requires published, not in_review
        ->and($l->roundStatus(3, 2))->toBe('draft')
        ->and($l->application(1, false))->toMatchArray(['status' => 'pending', 'round_status' => 'submitted'])
        ->and($l->application(2, false))->toMatchArray(['status' => 'approved', 'round_status' => 'submitted']);
});

it('lands scored, awarded, active and monitoring states like the real controllers', function () {
    expect(StartPointCatalog::landing('finalization')->application(2, true)['round_status'])->toBe('scored');
    $f = StartPointCatalog::landing('funding_setup');
    expect($f->program()['status'])->toBe('awarded')
        ->and($f->application(1, false))->toMatchArray(['status' => 'awarded', 'round_status' => 'advanced', 'funding_setup_status' => 'not_started', 'planning_mode' => 'locked'])
        ->and($f->milestone(1)['is_template'])->toBeTrue();
    $d = StartPointCatalog::landing('disbursement');
    expect($d->application(1, false)['funding_setup_status'])->toBe('completed')
        ->and($d->milestone(1))->toMatchArray(['is_template' => false, 'fund_release_status' => 'approved'])
        ->and($d->milestone(2)['fund_release_status'])->toBe('locked');
    expect(StartPointCatalog::landing('monitoring')->program(true)['status'])->toBe('completed')
        ->and(StartPointCatalog::landing('setup')->program())->toBe(['status' => 'draft', 'visible' => false]);
});
