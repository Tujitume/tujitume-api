<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Programs\SupplierDirectory;

/**
 * A supplier is opened, changed and removed only by the owner who added them (the same rule as the directory list),
 * and the drawer gets how they are paid.
 */
class SupplierDirectoryAccessTest extends ProgramTestCase
{
    private function supplier(): SupplierDirectory
    {
        return SupplierDirectory::create(['added_by' => $this->orgUser->id, 'legal_name' => 'Agro Supplies Ltd', 'phone' => '0700000001', 'email' => 'sales@agro.example',
            'payment_method' => 'mpesa_paybill', 'mpesa_paybill_number' => '222111', 'mpesa_paybill_account' => 'AGRO', 'is_active' => true]);
    }

    public function test_the_owner_opens_a_supplier_they_added_with_how_they_are_paid(): void
    {
        $supplier = $this->supplier();

        $data = $this->actingAsOrgOwner()->getJson("/api/v1/programs/supplier-directory/{$supplier->id}")->assertOk()->json('data');

        $this->assertSame('mpesa_paybill', $data['payment_method']);
        $this->assertSame(0, $data['payment_history']['payments']);
        $this->assertFalse($data['payment_history']['has_tujitume_wallet']);
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/supplier-directory/{$supplier->id}/assigned-milestones")->assertOk();
    }

    public function test_someone_else_cannot_open_change_or_remove_it(): void
    {
        $supplier = $this->supplier();
        $other = User::factory()->create(['user_type_id' => 4]);
        $this->createVerifiedKyc($other, 'organization');

        $this->actingAs($other, 'sanctum')->getJson("/api/v1/programs/supplier-directory/{$supplier->id}")->assertNotFound();
        $this->actingAs($other, 'sanctum')->putJson("/api/v1/programs/supplier-directory/{$supplier->id}", ['legal_name' => 'Taken'])->assertNotFound();
        $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/programs/supplier-directory/{$supplier->id}")->assertNotFound();
        $this->assertSame('Agro Supplies Ltd', $supplier->fresh()->legal_name);
    }
}
