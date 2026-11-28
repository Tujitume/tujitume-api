<?php

use App\Models\Admin;
use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use Illuminate\Support\Facades\Hash;

// The admin KYC list: the reject form (reason box + button) must be outside the table. A <form> inside <tbody> is
// closed by the browser straight away, which left the reason and the "Reject KYC" button outside it, so it never sent.

it('renders the review and reject modals after the table, with the reason inside the reject form', function () {
    $admin = Admin::firstOrCreate(['email' => 'kyc-page@tujitume.test'], ['name' => 'Ops', 'password' => Hash::make('secret')]);
    $user = User::factory()->create(['user_type_id' => 1, 'first_name' => 'Tina', 'last_name' => 'Table']);
    $kyc = KycVerification::create(['user_id' => $user->id, 'verification_type' => 'entrepreneur', 'status' => 'submitted', 'submitted_at' => now()]);

    $html = $this->actingAs($admin, 'admin')->get(route('admin.kyc.index', ['status' => 'submitted']))->assertOk()->getContent();

    $tableEnd = strpos($html, '</table>');
    $rejectForm = strpos($html, 'action="'.route('admin.kyc.reject', $kyc).'"');
    expect($tableEnd)->not->toBeFalse()
        ->and($rejectForm)->toBeGreaterThan($tableEnd)
        ->and(strpos($html, 'id="kycDetails'.$kyc->id.'"'))->toBeGreaterThan($tableEnd);

    // Inside that form: the token, the reason box and the submit button
    $form = substr($html, $rejectForm, strpos($html, '</form>', $rejectForm) - $rejectForm);
    expect($form)->toContain('name="_token"')->toContain('name="rejection_reason"')->toContain('Reject KYC');
});

// An individual investor is checked with the entrepreneur's identity form, but the admin list names them an investor
it('shows an investor as Investor in the type column, not Entrepreneur', function () {
    $admin = Admin::firstOrCreate(['email' => 'kyc-page@tujitume.test'], ['name' => 'Ops', 'password' => Hash::make('secret')]);
    $investor = User::factory()->create(['user_type_id' => 2, 'first_name' => 'Ivy', 'last_name' => 'Investor']);
    KycVerification::create(['user_id' => $investor->id, 'verification_type' => 'entrepreneur', 'status' => 'submitted', 'submitted_at' => now()]);

    $html = $this->actingAs($admin, 'admin')->get(route('admin.kyc.index', ['status' => 'submitted']))->assertOk()->getContent();
    $row = substr($html, strpos($html, 'Ivy Investor'), 600);

    expect($row)->toContain('<td>Investor</td>')->not->toContain('<td>Entrepreneur</td>');
});
