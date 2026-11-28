<?php

use App\Models\Auth\User;
use App\Support\CollectionList;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

/** Users described the declarative way: options, entity-like labels, a number range, a boolean and a date range. */
function describedUsers(array $query): ListQuery
{
    return ListQuery::for(User::query(), Request::create('/x', 'GET', $query))
        ->search(['first_name'])
        ->options('type', 'user_type_id', label: 'app:type', values: [1, 2, 9], optionLabels: [1 => 'app:entrepreneur', 2 => 'Investor'])
        ->options('initial', DB::raw('substr(users.first_name, 1, 1)'), label: 'app:initial')
        ->options('kind', fn (Builder $q, array $v) => $q->whereIn('user_type_id', $v), label: 'app:kind', countBy: fn (Builder $q) => [['value' => 'x', 'count' => $q->count()]])
        ->entity('person', 'id', User::class, 'first_name', label: 'app:person')
        ->numberRange('level', 'user_type_id', label: 'app:level')
        ->boolean('investor', fn (Builder $q, bool $yes) => $yes ? $q->where('user_type_id', 2) : $q->where('user_type_id', '!=', 2), label: 'app:investor')
        ->dateRange('created', 'created_at', label: 'app:created', presets: ['today', 'last7Days'])
        ->dateRange('legacy', 'created_at')                      // old style: filters, but not described
        ->filter('plain', 'user_type_id');                       // old style: filters, but not described
}

beforeEach(function () {
    User::factory()->create(['first_name' => 'Amina', 'user_type_id' => 1]);
    User::factory()->create(['first_name' => 'Bo', 'user_type_id' => 2]);
    User::factory()->create(['first_name' => 'Ben', 'user_type_id' => 2]);
    User::factory()->create(['first_name' => 'Cara', 'user_type_id' => 3]);
});

it('describes every declared filter in order, and leaves old unlabelled ones out', function () {
    $schema = describedUsers([])->filterSchema();

    expect(array_column($schema, 'key'))->toBe(['type', 'initial', 'kind', 'person', 'level', 'investor', 'created'])
        ->and(array_column($schema, 'type'))->toBe(['options', 'options', 'options', 'options', 'numberRange', 'boolean', 'dateRange'])
        ->and($schema[0]['label'])->toBe('app:type')
        ->and($schema[0]['fallback'])->toBe('Type');
});

it('lists options with counts, known values first (0 when unused) and server labels', function () {
    $type = collect(describedUsers([])->filterSchema())->firstWhere('key', 'type');

    expect($type['options'])->toBe([
        ['value' => '1', 'count' => 1, 'label' => 'app:entrepreneur'],
        ['value' => '2', 'count' => 2, 'label' => 'Investor'],
        ['value' => '9', 'count' => 0],
        ['value' => '3', 'count' => 1],
    ])->and($type['searchable'])->toBeFalse();
});

it('counts an option filter with the other filters applied but not its own', function () {
    $schema = collect(describedUsers(['filters' => ['type' => [2], 'investor' => 'yes']])->filterSchema())->keyBy('key');

    // picking type 2 does not hide the other types' counts, but the investor=yes filter does
    expect(collect($schema['type']['options'])->pluck('count', 'value')->all())->toEqual(['1' => 0, '2' => 2, '9' => 0]);
    expect(collect($schema['initial']['options'])->pluck('count', 'value')->sort()->all())->toEqual(['B' => 2]);
    expect($schema['kind']['options'])->toBe([['value' => 'x', 'count' => 2]]);
});

it('filters by an expression option and names entity ids', function () {
    expect(describedUsers(['filters' => ['initial' => ['B']]])->fetch()[0]->pluck('first_name')->sort()->values()->all())->toBe(['Ben', 'Bo']);

    $person = collect(describedUsers([])->filterSchema())->firstWhere('key', 'person');
    expect($person['searchable'])->toBeTrue()
        ->and(collect($person['options'])->pluck('label')->sort()->values()->all())->toBe(['Amina', 'Ben', 'Bo', 'Cara']);
});

it('parses number ranges min~max with either side open and ignores junk', function () {
    $names = fn (array $q) => describedUsers($q)->fetch()[0]->pluck('first_name')->sort()->values()->all();

    expect($names(['filters' => ['level' => '2~3']]))->toBe(['Ben', 'Bo', 'Cara'])
        ->and($names(['filters' => ['level' => '~1']]))->toBe(['Amina'])
        ->and($names(['filters' => ['level' => '3~']]))->toBe(['Cara'])
        ->and($names(['filters' => ['level' => '1.5~2.5']]))->toBe(['Ben', 'Bo'])
        ->and($names(['filters' => ['level' => "x'~;drop"]]))->toHaveCount(4);

    expect(ListQuery::numberBounds('-5~10.25'))->toBe([-5.0, 10.25])
        ->and(ListQuery::numberBounds('abc~'))->toBe([null, null]);
});

it('gives number bounds from the data, with the other filters applied', function () {
    $level = fn (array $q) => collect(describedUsers($q)->filterSchema())->firstWhere('key', 'level');

    expect($level([]))->toMatchArray(['min' => 1, 'max' => 3])
        ->and($level(['search' => 'b', 'filters' => ['level' => '3~3']]))->toMatchArray(['min' => 2, 'max' => 2]);
});

it('applies a boolean as yes, no or any and counts both sides', function () {
    $count = fn ($v) => describedUsers(['filters' => ['investor' => $v]])->fetch()[0]->count();

    expect($count('yes'))->toBe(2)->and($count(['1']))->toBe(2)
        ->and($count('no'))->toBe(2)->and($count('false'))->toBe(2)
        ->and($count(['yes', 'no']))->toBe(4)->and($count('maybe'))->toBe(4);

    $investor = collect(describedUsers(['filters' => ['investor' => 'yes']])->filterSchema())->firstWhere('key', 'investor');
    expect($investor['options'])->toBe([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 2]]);
});

it('applies a boolean on a plain 1/0 column (no = 0 or empty)', function () {
    DB::table('users')->where('first_name', 'Cara')->update(['user_type_id' => 0]);
    $names = fn ($v) => ListQuery::for(User::query()->whereIn('first_name', ['Amina', 'Cara']), Request::create('/x', 'GET', ['filters' => ['flag' => $v]]))
        ->boolean('flag', 'user_type_id', label: 'app:flag')->fetch()[0]->pluck('first_name')->all();

    expect($names('yes'))->toBe(['Amina'])->and($names('no'))->toBe(['Cara'])->and($names(''))->toHaveCount(2);
});

it('keeps date presets, and old dateRange calls still filter', function () {
    $created = collect(describedUsers([])->filterSchema())->firstWhere('key', 'created');
    expect($created['presets'])->toBe(['today', 'last7Days']);

    expect(describedUsers(['filters' => ['legacy' => '2001-01-01~2001-01-02']])->fetch()[0])->toHaveCount(0)
        ->and(describedUsers(['filters' => ['plain' => [3]]])->fetch()[0])->toHaveCount(1);

    $default = ListQuery::for(User::query(), Request::create('/x'))->dateRange('d', 'created_at', label: 'app:date')->filterSchema();
    expect($default[0]['presets'])->toBe(ListQuery::DATE_PRESETS);
});

it('takes a closure date range (a day kept as text)', function () {
    $seen = [];
    ListQuery::for(User::query(), Request::create('/x', 'GET', ['filters' => ['d' => '2026-01-01~bad']]))
        ->dateRange('d', function (Builder $q, ?string $from, ?string $to) use (&$seen) { $seen = [$from, $to]; }, label: 'app:date')
        ->fetch();

    expect($seen)->toBe(['2026-01-01', null]);
});

it('sends no schema for an export', function () {
    expect(describedUsers(['export' => 1])->filterSchema())->toBe([]);
});

it('describes and applies the same filter types over a collection', function () {
    $rows = [
        ['kind' => 'fee', 'amount' => 100, 'paid' => true, 'at' => '2026-03-01 10:00:00'],
        ['kind' => 'fee', 'amount' => 250.5, 'paid' => false, 'at' => '2026-03-05'],
        ['kind' => 'grant', 'amount' => 900, 'paid' => true, 'at' => '2026-04-01'],
    ];
    $list = fn (array $q) => CollectionList::for($rows, Request::create('/x', 'GET', $q))
        ->options('kind', fn ($r) => $r['kind'], label: 'app:type', values: ['grant', 'loan'], optionLabels: ['fee' => 'app:fee'])
        ->numberRange('amount', fn ($r) => $r['amount'], label: 'app:amount')
        ->boolean('paid', fn ($r) => $r['paid'], label: 'app:paid')
        ->dateRange('date', fn ($r) => $r['at'], label: 'app:date')
        ->filter('legacy', fn ($r) => $r['kind']);

    $schema = collect($list(['filters' => ['paid' => 'yes']])->filterSchema())->keyBy('key');
    expect($schema->keys()->all())->toBe(['kind', 'amount', 'paid', 'date'])
        ->and($schema['kind']['options'])->toBe([
            ['value' => 'grant', 'count' => 1], ['value' => 'loan', 'count' => 0], ['value' => 'fee', 'count' => 1, 'label' => 'app:fee'],
        ])
        ->and($schema['amount'])->toMatchArray(['min' => 100, 'max' => 900])
        ->and($schema['paid']['options'])->toBe([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]])
        ->and($schema['date']['presets'])->toBe(ListQuery::DATE_PRESETS);

    $amounts = fn (array $q) => $list($q)->fetch()[0]->pluck('amount')->all();
    expect($amounts(['filters' => ['amount' => '200~']]))->toBe([250.5, 900])
        ->and($amounts(['filters' => ['amount' => '~250.5', 'paid' => 'no']]))->toBe([250.5])
        ->and($amounts(['filters' => ['amount' => 'x~y']]))->toHaveCount(3)
        ->and($amounts(['filters' => ['date' => '2026-03-01~2026-03-31', 'kind' => ['fee']]]))->toBe([100, 250.5])
        ->and($list(['export' => 1])->filterSchema())->toBe([]);
});
