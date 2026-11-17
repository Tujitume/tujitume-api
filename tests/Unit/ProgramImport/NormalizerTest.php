<?php

use App\Modules\ProgramImport\Normalizers\Normalizer;
use App\Modules\ProgramImport\Normalizers\RowNormalizer;

it('parses money with currency and separators but never guesses 50k', function () {
    expect(Normalizer::money('KES 50,000')['value'])->toBe(50000.0)
        ->and(Normalizer::money('1250.5')['value'])->toBe(1250.5)
        ->and(Normalizer::money('50k')['error'])->not->toBeNull()
        ->and(Normalizer::money('-5')['error'])->not->toBeNull()
        ->and(Normalizer::money('')['value'])->toBeNull();
});

it('parses dates with an explicit format and flags ambiguous day/month', function () {
    expect(Normalizer::date('2026-03-04')['value'])->toBe('2026-03-04')
        ->and(Normalizer::date('04/03/2026', 'd/m/Y')['value'])->toBe('2026-03-04')
        ->and(Normalizer::date('04/03/2026')['error'])->toContain('ambiguous')
        ->and(Normalizer::date('25/03/2026')['value'])->toBe('2026-03-25')
        ->and(Normalizer::date('2026-02-31')['error'])->not->toBeNull();
});

it('neutralizes formulas and strips html in text', function () {
    expect(Normalizer::text('=HYPERLINK("x")')['value'])->toStartWith("'=")
        ->and(Normalizer::text('<b>Hi</b><script>x</script>')['value'])->toBe('Hix')
        ->and(Normalizer::text('@cmd')['value'])->toStartWith("'@");
});

it('normalizes phones, emails, urls, lists and enums', function () {
    expect(Normalizer::phone('0712 345 678', '254')['value'])->toBe('+254712345678')
        ->and(Normalizer::phone('+254712345678')['value'])->toBe('+254712345678')
        ->and(Normalizer::phone('abc')['error'])->not->toBeNull()
        ->and(Normalizer::email(' A@B.com ')['value'])->toBe('a@b.com')
        ->and(Normalizer::url('javascript:alert(1)')['error'])->not->toBeNull()
        ->and(Normalizer::list('a; b ;c')['value'])->toBe(['a', 'b', 'c'])
        ->and(Normalizer::enum('Won', ['awarded', 'rejected'], ['Won' => 'awarded'])['value'])->toBe('awarded')
        ->and(Normalizer::enum('multi round', ['single_round', 'multi_round'])['value'])->toBe('multi_round')
        ->and(Normalizer::enum('x', ['a'])['error'])->not->toBeNull();
});

it('reports required fields and normalizes a whole program row', function () {
    $r = RowNormalizer::normalize('program', ['program_title' => 'Seed Fund', 'total_program_amount' => 'KES 1,000,000']);
    expect($r['errors'])->toHaveKeys(['funding_per_business', 'disbursement_type', 'program_industry'])
        ->and($r['values']['total_program_amount'])->toBe(1000000.0)
        ->and($r['values']['total_rounds'])->toBe(1);
});
