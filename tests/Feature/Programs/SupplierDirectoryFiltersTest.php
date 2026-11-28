<?php

namespace Tests\Feature\Programs;

use App\Models\Programs\MilestoneSupplier;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\SupplierDirectory;

/** The program side's supplier directory panel is described by the server (`data.filter_schema`) and every filter narrows the list. */
class SupplierDirectoryFiltersTest extends ProgramTestCase
{
    private function directory(array $query)
    {
        return $this->actingAsOrgOwner()->getJson('/api/v1/programs/supplier-directory?'.http_build_query($query))->assertOk();
    }

    public function test_the_supplier_filters_are_described_and_each_one_filters(): void
    {
        $owner = ['user_id' => $this->orgUser->id];
        $wallet = SupplierDirectory::factory()->create($owner + ['payment_method' => 'mpesa_lipr', 'lipr_wallet' => 'W-1', 'supplier_type' => 'transport']);
        $paybill = SupplierDirectory::factory()->create($owner + ['payment_method' => 'mpesa_paybill', 'mpesa_paybill_number' => '400200', 'mpesa_paybill_account' => '', 'is_active' => false]);
        $bank = SupplierDirectory::factory()->create($owner + ['payment_method' => 'bank_transfer', 'bank_name' => 'KCB', 'bank_account_number' => '1234']);
        // someone else's supplier never shows
        SupplierDirectory::factory()->create();

        foreach ([1, 2] as $order) {
            MilestoneSupplier::create(['milestone_id' => ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => $order])->id, 'supplier_id' => $bank->id]);
        }

        $schema = collect($this->directory(['page' => 1, 'per_page' => 10])->json('filter_schema'))->keyBy('key');
        $this->assertSame(['status', 'category', 'payment', 'onboarding', 'milestones', 'payout_ready', 'added'], $schema->keys()->all());
        $this->assertSame(['options', 'options', 'options', 'options', 'numberRange', 'boolean', 'dateRange'], $schema->pluck('type')->all());
        $this->assertSame(['active' => 2, 'inactive' => 1], collect($schema['status']['options'])->pluck('count', 'value')->all());
        $this->assertSame(['value' => 'mpesa_lipr', 'count' => 1, 'label' => 'grant:supplierDirectory.methodLiprWallet'], $schema['payment']['options'][0]);
        $this->assertSame(['materials' => 2, 'transport' => 1], collect($schema['category']['options'])->pluck('count', 'value')->all());
        $this->assertSame([0, 2], [$schema['milestones']['min'], $schema['milestones']['max']]);
        // the paybill one has no account number yet
        $this->assertSame([['value' => 'yes', 'count' => 2], ['value' => 'no', 'count' => 1]], $schema['payout_ready']['options']);

        $ids = fn (array $filters) => collect($this->directory(['export' => 1, 'filters' => $filters])->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$bank->id], $ids(['milestones' => '1~']));
        $this->assertSame([$paybill->id], $ids(['payout_ready' => 'no']));
        $this->assertSame([$paybill->id], $ids(['status' => ['inactive']]));
        $this->assertSame([$wallet->id], $ids(['category' => ['transport']]));
        $this->assertSame([$wallet->id, $bank->id], $ids(['payment' => ['mpesa_lipr', 'bank_transfer']]));
        $this->assertSame([$wallet->id, $paybill->id, $bank->id], $ids(['onboarding' => ['onboarded'], 'added' => now()->toDateString().'~']));
        $this->assertSame([], $ids(['added' => '~'.now()->subDays(2)->toDateString()]));
        // counts follow the other filters
        $status = collect($this->directory(['page' => 1, 'per_page' => 10, 'filters' => ['payout_ready' => 'yes']])->json('filter_schema'))->firstWhere('key', 'status');
        $this->assertSame(['active' => 2, 'inactive' => 0], collect($status['options'])->pluck('count', 'value')->all());
    }
}
