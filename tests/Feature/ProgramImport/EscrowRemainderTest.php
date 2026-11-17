<?php

use App\Http\Controllers\CheckoutMpesaController;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Http\Request;
use Tests\Feature\ProgramImport\ImportTestCase;

uses(ImportTestCase::class);

function pi_escrow_amount($t, string $purpose, $listingId): array
{
    $t->actingAs($t->orgUser, 'sanctum');
    $controller = app(CheckoutMpesaController::class);
    $m = new ReflectionMethod($controller, 'resolveInitiatePaymentDetails');
    $m->setAccessible(true);

    return $m->invoke($controller, Request::create('/x', 'POST', ['purpose' => $purpose, 'listing_id' => $listingId]), 'https://cb');
}

function pi_partial_app($t, bool $paid): array
{
    $winners = [['application_ref' => 'W1', 'program_ref' => 'P1', 'startup_name' => 'Agro One', 'email' => 'a@example.com', 'awarded_amount' => 100000, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi']];
    $ms = [['application_ref' => 'W1', 'milestone_ref' => 'M1', 'sequence_order' => 1, 'title' => 'Equipment', 'amount' => 60000, 'status' => $paid ? 'completion_approved' : 'pending'],
        ['application_ref' => 'W1', 'milestone_ref' => 'M2', 'sequence_order' => 2, 'title' => 'Launch', 'amount' => 40000]];
    $b = $t->newBatch('disbursement', $t->xlsx(['program' => [$t->programRow()], 'winners' => $winners, 'milestones' => $ms]));
    $r = $t->prepare($b);
    expect($r['blockers'])->toBe([]);
    $t->commit($b);

    return ProgramMilestone::where('app_id', ProgramApplication::first()->id)->orderBy('sequence_order')->get()->all();
}

it('charges the full application amount into escrow when nothing is released (unchanged behavior)', function () {
    [$m1, $m2] = pi_partial_app($this, false);
    expect((float) pi_escrow_amount($this, 'program_disbursement_escrow', $m1->id)['amount'])->toBe(100000.0);
});

it('charges only the unreleased remainder into escrow when some milestones are already paid', function () {
    [$m1, $m2] = pi_partial_app($this, true);
    expect($m1->fund_release_status)->toBe('released')->and($m2->fund_release_status)->toBe('approved');
    expect((float) pi_escrow_amount($this, 'program_disbursement_escrow', $m2->id)['amount'])->toBe(40000.0);
});

it('the bulk payment counts the first unreleased milestone, not one that is already paid', function () {
    [$m1, $m2] = pi_partial_app($this, true);
    $app = ProgramApplication::first();
    expect((float) pi_escrow_amount($this, 'program_milestone_bulk', [$app->id])['amount'])->toBe(40000.0);
    $m1->update(['fund_release_status' => 'approved']);
    $m2->update(['fund_release_status' => 'locked']);
    $m1->update(['fund_released' => false]);
    expect((float) pi_escrow_amount($this, 'program_milestone_bulk', [$app->id])['amount'])->toBe(60000.0);
});
