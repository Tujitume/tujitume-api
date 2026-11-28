<?php

use App\Models\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// When a person (business owner, investor, service provider) submits their verification they get a receipt by email,
// branded Tujitume, with a button back to their own verification page.

function kycReceiptMails(): ArrayObject
{
    $sent = new ArrayObject();
    Event::listen(MessageSent::class, function (MessageSent $e) use ($sent) {
        foreach ($e->message->getTo() as $to) {
            $sent[] = ['to' => $to->getAddress(), 'subject' => $e->message->getSubject(), 'html' => $e->message->getHtmlBody()];
        }
    });

    return $sent;
}

it('emails a receipt when a person submits their verification', function (int $type, string $path) {
    Storage::fake('kyc');
    $mails = kycReceiptMails();
    $user = User::factory()->create(['user_type_id' => $type, 'first_name' => 'Rose', 'last_name' => 'Receipt', 'country' => 'KE']);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc')->assertCreated();
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/kyc', $type === 3
        ? ['legal_name' => 'Rose Receipt', 'id_type' => 'passport', 'id_number' => 'P123', 'phone' => '+254712345678', 'email' => 'rose@example.test']
        : ['legal_name' => 'Rose Receipt', 'id_type' => 'national_id', 'id_number' => '123', 'id_issuing_country' => 'KE', 'id_expiry_date' => now()->addYears(3)->toDateString(), 'nationality' => 'KE'])->assertOk();
    foreach (['id_passport_copy', 'tax_pin_document'] as $doc) {
        $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')
            ->post('/api/v1/kyc/documents', ['document_type' => $doc, 'file' => UploadedFile::fake()->create("$doc.pdf", 20, 'application/pdf')])->assertCreated();
    }
    expect((array) $mails)->toBe([]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/kyc/submit')->assertOk()->assertJsonPath('data.status', 'submitted');

    $receipts = collect((array) $mails)->where('to', $user->email)->values();
    expect($receipts)->toHaveCount(1)
        ->and($receipts[0]['subject'])->toBe('We received your Tujitume verification')
        ->and($receipts[0]['html'])->toContain('Rose Receipt')->toContain('Tujitume')->toContain($path);
})->with([
    // The button opens the app's /go deep link to the person's own verification page
    'entrepreneur' => [1, '/go?to=dashboard.entrepreneur.settings.verify'],
    'investor' => [2, '/go?to=dashboard.investor.settings%3Ftab%3Dverification'],
    'service provider' => [3, '/go?to=dashboard.serviceProvider.kyc'],
]);
