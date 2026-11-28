<?php

use App\Models\Auth\User;
use App\Support\ListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(Tests\TestCase::class, RefreshDatabase::class);

function listFor(array $query): ListQuery
{
    return ListQuery::for(User::query(), Request::create('/x', 'GET', $query))
        ->search(['first_name', 'last_name', 'email'])
        ->filter('type', 'user_type_id')
        ->dateRange('created', 'created_at')
        ->sortable(['first_name', 'created_at'], default: 'first_name');
}

beforeEach(function () {
    User::factory()->create(['first_name' => 'Amina', 'last_name' => 'Ali', 'email' => 'amina@a.test', 'user_type_id' => 1]);
    User::factory()->create(['first_name' => 'Bo', 'last_name' => 'Kim', 'email' => 'bo@b.test', 'user_type_id' => 2]);
    User::factory()->create(['first_name' => 'Cara', 'last_name' => '100% Real', 'email' => 'cara@c.test', 'user_type_id' => 2]);
});

it('finds rows when every word matches in any column, in any case and order', function () {
    expect(listFor(['search' => 'ALI amina'])->fetch()[0]->pluck('first_name')->all())->toBe(['Amina']);
    expect(listFor(['search' => 'amina kim'])->fetch()[0])->toHaveCount(0);
    expect(listFor(['search' => '  b.test  '])->fetch()[0]->pluck('first_name')->all())->toBe(['Bo']);
});

it('treats % and _ typed by the person as text', function () {
    expect(listFor(['search' => '100%'])->fetch()[0]->pluck('first_name')->all())->toBe(['Cara']);
    expect(listFor(['search' => '%'])->fetch()[0]->pluck('first_name')->all())->toBe(['Cara']);
    expect(listFor(['search' => '_'])->fetch()[0])->toHaveCount(0);
});

it('filters by any value of a key and combines keys', function () {
    expect(listFor(['filters' => ['type' => [2]]])->fetch()[0])->toHaveCount(2);
    expect(listFor(['filters' => ['type' => [1, 2]]])->fetch()[0])->toHaveCount(3);
    expect(listFor(['filters' => ['type' => [2]], 'search' => 'cara'])->fetch()[0])->toHaveCount(1);
    expect(listFor(['filters' => ['nope' => ['x']]])->fetch()[0])->toHaveCount(3); // unknown filter ignored
});

it('filters a date range and ignores malformed dates', function () {
    $today = now()->toDateString();
    expect(listFor(['filters' => ['created' => $today.'~'.$today]])->fetch()[0])->toHaveCount(3);
    expect(listFor(['filters' => ['created' => '2001-01-01~2001-01-02']])->fetch()[0])->toHaveCount(0);
    expect(listFor(['filters' => ['created' => "x'; drop table users;--~"]])->fetch()[0])->toHaveCount(3);
});

it('sorts by registered keys only, falling back to the default', function () {
    expect(listFor(['sort' => '-first_name'])->fetch()[0]->pluck('first_name')->all())->toBe(['Cara', 'Bo', 'Amina']);
    expect(listFor(['sort' => 'password'])->fetch()[0]->pluck('first_name')->all())->toBe(['Amina', 'Bo', 'Cara']);
});

it('pages with a total, caps per_page and keeps pages stable', function () {
    User::factory()->count(30)->create();
    [$rows, $page] = listFor(['page' => 2, 'per_page' => 10])->fetch();
    expect($rows)->toHaveCount(10)->and($page->total())->toBe(33)->and($page->lastPage())->toBe(4);
    [, $big] = listFor(['page' => 1, 'per_page' => 9999])->fetch();
    expect($big->perPage())->toBe(100);
    $seen = collect(range(1, 4))->flatMap(fn ($p) => listFor(['page' => $p, 'per_page' => 10, 'sort' => 'created_at'])->fetch()[0]->pluck('id'));
    expect($seen->unique()->count())->toBe(33);
});

it('exports every match instead of a page', function () {
    User::factory()->count(30)->create(['user_type_id' => 3]);
    [$rows, $page] = listFor(['export' => 1, 'per_page' => 10, 'filters' => ['type' => [3]]])->fetch();
    expect($rows)->toHaveCount(30)->and($page)->toBeNull();
});

it('counts filter options with the other filters applied', function () {
    $facets = listFor(['filters' => ['type' => [1]], 'search' => 'a'])->facets(['type']);
    $byValue = collect($facets['type'])->pluck('count', 'value')->all();
    expect($byValue)->toEqual([1 => 1, 2 => 1]); // the type filter does not hide the other types' counts
});

it('exports every match with no cap: past MAX_PER_PAGE and across read chunks, even with no sort', function () {
    User::factory()->count(520)->create(['user_type_id' => 4]);
    $list = ListQuery::for(User::query(), Request::create('/x', 'GET', ['export' => 1, 'filters' => ['type' => [4]]]))->filter('type', 'user_type_id');
    [$rows, $page] = $list->fetch();
    expect($rows)->toHaveCount(520)->and($rows->pluck('id')->unique())->toHaveCount(520)
        ->and($page)->toBeNull()->and($list->exportTruncated())->toBeFalse();
});
