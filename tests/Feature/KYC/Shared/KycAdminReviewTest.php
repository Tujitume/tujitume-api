<?php

use App\Models\Admin;
use App\Models\Auth\User;
use App\Models\Communication\Notifications;
use App\Models\Kyc\KycVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// Admin review of a person's KYC: reject with a reason (the person is told in-app and by email, sees the reason,
// fixes it and resubmits), then verify. Also: removing a saved document, and calls made before a draft exists.

/** Subjects of the emails sent to an address while the test runs */
function kycMailTo(): ArrayObject
{
    $sent = new ArrayObject();
    Event::listen(MessageSent::class, function (MessageSent $e) use ($sent) {
        foreach ($e->message->getTo() as $to) $sent[] = $to->getAddress().' | '.$e->message->getSubject();
    });

    return $sent;
}

function kycReviewAdmin(): Admin
{
    return Admin::firstOrCreate(['email' => 'kyc-review@tujitume.test'], ['name' => 'Ops', 'password' => Hash::make('secret')]);
}

function kycSubmittedPerson($t, int $type = 1): User
{
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => $type, 'first_name' => 'Rita', 'last_name' => 'Review', 'country' => 'KE']);
    $t->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $t->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', $type === 3
        ? ['legal_name' => 'Rita Review', 'id_type' => 'passport', 'id_number' => 'P123', 'phone' => '+254712345678', 'email' => 'rita@example.test']
        : ['legal_name' => 'Rita Review', 'id_type' => 'national_id', 'id_number' => '123', 'id_issuing_country' => 'KE', 'id_expiry_date' => now()->addYears(3)->toDateString(), 'nationality' => 'KE'])->assertOk();
    foreach (['id_passport_copy', 'tax_pin_document'] as $doc) {
        $t->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')
            ->post('/api/v1/kyc/documents', ['document_type' => $doc, 'file' => UploadedFile::fake()->create("$doc.pdf", 20, 'application/pdf')])->assertCreated();
    }
    $t->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertOk()->assertJsonPath('data.status', 'submitted');

    return $user;
}

dataset('person types', [
    'entrepreneur' => [1, 'dashboard.entrepreneur.settings.verify'],
    'investor' => [2, 'dashboard.investor.settings?tab=verification'],
    'service provider' => [3, 'dashboard.serviceProvider.kyc'],
]);

it('tells the person why their KYC was rejected, in the app and by email, and lets them fix it and resubmit', function (int $type, string $link) {
    $mail = kycMailTo();
    $user = kycSubmittedPerson($this, $type);
    $kyc = KycVerification::where('user_id', $user->id)->firstOrFail();

    $this->actingAs(kycReviewAdmin(), 'admin')->post(route('admin.kyc.reject', $kyc), ['rejection_reason' => 'The ID copy is blurry.'])
        ->assertRedirect()->assertSessionHas('success');

    $notice = Notifications::where('receiver_id', $user->id)->latest('id')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->text)->toContain('The ID copy is blurry.')
        ->and($notice->link)->toBe($link);
    // One rejection email (a submission receipt may also have been sent when they submitted)
    expect(array_count_values((array) $mail)[$user->email.' | Your Tujitume verification needs changes'] ?? 0)->toBe(1);

    // The person sees the reason, and the form is open again
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/kyc')->assertOk()
        ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'The ID copy is blurry.');
    $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')
        ->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => UploadedFile::fake()->create('clear-id.pdf', 20, 'application/pdf')])->assertCreated();
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', ['legal_name' => 'Rita A. Review'])->assertOk()->assertJsonPath('data.status', 'draft');
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertOk()->assertJsonPath('data.status', 'submitted');

    $this->actingAs(kycReviewAdmin(), 'admin')->post(route('admin.kyc.verify', $kyc))->assertRedirect()->assertSessionHas('success');
    expect($kyc->fresh()->status)->toBe('verified')
        ->and((array) $mail)->toContain($user->email.' | You are verified on Tujitume')
        ->and(Notifications::where('receiver_id', $user->id)->latest('id')->value('text'))->toContain('verification was successful');
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', ['legal_name' => 'Changed'])->assertStatus(409);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertStatus(409);
})->with('person types');

it('needs a reason to reject, and only rejects a submitted record', function () {
    $mail = kycMailTo();
    $user = kycSubmittedPerson($this);
    $kyc = KycVerification::where('user_id', $user->id)->firstOrFail();
    $this->actingAs(kycReviewAdmin(), 'admin')->post(route('admin.kyc.reject', $kyc), ['rejection_reason' => ''])->assertInvalid('rejection_reason');
    expect($kyc->fresh()->status)->toBe('submitted');
    $kyc->update(['status' => 'verified']);
    $this->actingAs(kycReviewAdmin(), 'admin')->post(route('admin.kyc.reject', $kyc), ['rejection_reason' => 'Late'])->assertSessionHas('error');
    expect($kyc->fresh()->status)->toBe('verified');
    expect(collect((array) $mail)->filter(fn ($m) => str_contains($m, 'needs changes'))->all())->toBe([]);
});

it('deletes a saved document the person removed, so it no longer counts for submission', function () {
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $id = $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')
        ->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => UploadedFile::fake()->create('id.pdf', 20, 'application/pdf')])->assertCreated()->json('data.id');
    $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/kyc/documents/$id")->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/kyc')->assertOk()->assertJsonCount(0, 'data.documents');
});

it('answers 404, not a server error, when the form is saved before a draft exists', function () {
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', ['legal_name' => 'X'])->assertNotFound();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertNotFound();
    $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')
        ->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => UploadedFile::fake()->create('id.pdf', 20, 'application/pdf')])->assertNotFound();
    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/kyc/documents/999')->assertNotFound();
});

it('refuses a file that is not a PDF, JPG or PNG, or is over 10 MB', function () {
    Storage::fake('kyc');
    $user = User::factory()->create(['user_type_id' => 1]);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $post = fn ($file) => $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')->post('/api/v1/kyc/documents', ['document_type' => 'id_passport_copy', 'file' => $file]);
    $post(UploadedFile::fake()->create('notes.txt', 5, 'text/plain'))->assertUnprocessable()->assertJsonValidationErrors('file');
    $post(UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
});
