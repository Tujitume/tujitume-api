<?php

use App\Modules\ProgramImport\Mapping\HeuristicMapper;
use App\Modules\ProgramImport\Mapping\MappingApplier;

it('maps headers by key, label, synonym and near spelling, and ignores the rest', function () {
    $m = (new HeuristicMapper)->map('applications', ['Business Name', 'E-mail address', 'Amount', 'Locaton', 'Favourite colour'], ['q1' => 'Describe your impact on the community']);
    expect($m['columns']['Business Name']['target'])->toBe('startup_name')->and($m['columns']['E-mail address']['target'])->toBe('email')
        ->and($m['columns']['Amount']['target'])->toBe('total_amount_requested')->and($m['columns']['Locaton']['target'])->toBe('headquarters_location')
        ->and($m['ignored'])->toBe(['Favourite colour']);
    $q = (new HeuristicMapper)->map('applications', ['Describe your impact on the community'], ['q1' => 'Describe your impact on the community']);
    expect($q['columns']['Describe your impact on the community']['target'])->toBe('question:q1');
});

it('sanitizes user mappings to the registry allowlist', function () {
    $s = MappingApplier::sanitize('applications', ['columns' => ['A' => ['target' => 'startup_name'], 'B' => ['target' => 'users.password'], 'C' => ['target' => 'question:abc'], 'D' => ['target' => '']], 'value_maps' => ['outcome' => ['No' => 'rejected'], 'nope' => ['x' => 'y']], 'defaults' => ['sector' => 'Agri', 'evil' => 'x']]);
    expect(array_keys($s['columns']))->toBe(['A', 'C'])->and($s['ignored'])->toBe(['D'])->and($s['value_maps'])->toBe(['outcome' => ['No' => 'rejected']])->and($s['defaults'])->toBe(['sector' => 'Agri']);
});
