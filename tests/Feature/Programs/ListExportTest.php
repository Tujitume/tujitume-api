<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\SupplierDirectory;

/** Tables export every matching row (all pages), and their pages, search and filters work on the server. */
class ListExportTest extends ProgramTestCase
{
    private function suppliers(array $query = [])
    {
        return $this->actingAsOrgOwner()->getJson('/api/v1/programs/supplier-directory?'.http_build_query($query));
    }

    private function makeSuppliers(int $count, array $attributes = []): void
    {
        SupplierDirectory::factory()->count($count)->create($attributes + ['user_id' => $this->orgUser->id, 'added_by' => $this->orgUser->id]);
    }

    public function test_the_supplier_directory_pages_on_the_server_and_exports_every_match(): void
    {
        $this->makeSuppliers(25, ['supplier_type' => 'materials', 'payment_method' => 'bank_transfer']);
        $this->makeSuppliers(7, ['supplier_type' => 'transport', 'payment_method' => 'mpesa_till', 'is_active' => false]);
        // someone else's directory never shows
        SupplierDirectory::factory()->count(3)->create(['user_id' => $this->applicantUser->id, 'added_by' => $this->applicantUser->id]);

        $page = $this->suppliers(['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data'));
        $this->assertSame(32, $page->json('pagination.total'));
        $this->assertSame(32, $page->json('total')); // Laravel's page keys are still there
        $this->assertSame(32, $page->json('stats.total'));
        $this->assertSame(25, $page->json('stats.active'));

        $all = $this->suppliers(['export' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(32, $all->json('data'));
        $this->assertFalse($all->json('export_truncated'));
        $this->assertNull($all->json('pagination'));

        // the export follows the filters and the search
        $this->assertCount(7, $this->suppliers(['export' => 1, 'filters' => ['category' => ['transport']]])->json('data'));
        $this->assertCount(7, $this->suppliers(['export' => 1, 'filters' => ['status' => ['inactive']]])->json('data'));
        $this->assertCount(25, $this->suppliers(['export' => 1, 'filters' => ['payment' => ['bank_transfer']], 'search' => ''])->json('data'));
        $this->assertSame(7, $this->suppliers(['page' => 1, 'per_page' => 5, 'filters' => ['category' => ['transport']]])->json('pagination.total'));

        $facets = collect($page->json('facets.category'))->pluck('count', 'value')->all();
        $this->assertEquals(['materials' => 25, 'transport' => 7], $facets);
        $status = collect($page->json('facets.status'))->pluck('count', 'value')->all();
        $this->assertEquals(['active' => 25, 'inactive' => 7], $status);

        // without page / per_page the whole directory comes back (the supplier pickers read it)
        $this->assertCount(32, $this->suppliers()->json('data'));
    }

    public function test_the_supplier_directory_searches_and_filters_by_onboarding(): void
    {
        $this->makeSuppliers(3);
        SupplierDirectory::factory()->create(['user_id' => null, 'added_by' => $this->orgUser->id, 'legal_name' => 'Zawadi Hardware', 'email' => 'nobody-here@example.test']);

        $found = $this->suppliers(['page' => 1, 'per_page' => 10, 'search' => 'zawadi'])->json('data');
        $this->assertSame(['Zawadi Hardware'], collect($found)->pluck('legal_name')->all());

        $invited = $this->suppliers(['export' => 1, 'filters' => ['onboarding' => ['invited']]])->json('data');
        $this->assertSame(['Zawadi Hardware'], collect($invited)->pluck('legal_name')->all());
        $this->assertSame('invited', $invited[0]['status']);
        $this->assertCount(3, $this->suppliers(['export' => 1, 'filters' => ['onboarding' => ['onboarded']]])->json('data'));
    }

    public function test_awarded_applications_are_all_returned_not_only_the_first_page(): void
    {
        $other = Program::factory()->create(['user_id' => $this->orgUser->id, 'status' => 'published', 'visible' => 1]);
        ProgramApplication::factory()->count(23)->create(['program_id' => $other->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded']);
        // not awarded, and someone else's award, are left out
        ProgramApplication::factory()->create(['program_id' => $other->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'pending']);
        $someone = User::factory()->create(['user_type_id' => 1]);
        ProgramApplication::factory()->create(['program_id' => $other->id, 'user_id' => $someone->id, 'program_owner_id' => $this->orgUser->id, 'status' => 'awarded']);

        $url = '/api/v1/programs/my-applications/awarded';
        $all = $this->actingAsApplicant()->getJson($url)->assertOk();
        $this->assertCount(23, $all->json('applications'));
        $this->assertSame('awarded', $all->json('applications.0.status.value'));
        $this->assertCount(23, $this->actingAsApplicant()->getJson($url.'?export=1')->json('applications'));

        // a page when one is asked for, as before
        $page = $this->actingAsApplicant()->getJson($url.'?page=2&per_page=10')->assertOk();
        $this->assertCount(10, $page->json('applications.data'));
        $this->assertSame(23, $page->json('applications.total'));

        $this->actingAsOrgOwner()->getJson($url)->assertForbidden();
    }
}
