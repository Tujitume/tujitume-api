<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Transferred'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>A payment has been transferred to you for <strong>{{ $program_title }}</strong>. Please confirm receipt by clicking the button below.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Program:</strong> {{ $program_title }}</p>
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> {{ $amount }}</p>
            <p style="margin:0.5rem 0;"><strong>Payment Reference:</strong> {{ $payment_reference }}</p>
            <p style="margin:0.5rem 0;"><strong>Disbursement ID:</strong> {{ $disbursement_id }}</p>
        </div>

        <p>If you have received this payment, please confirm by clicking the button below.</p>

        @include('programs.partials.brand_button', ['label' => 'Confirm Receipt', 'url' => config('app.api_url') . 'program/disbursements/' . $disbursement_id . '/supplier-confirm?token=' . $confirmation_token])

        <p style="margin-top:1.5rem;font-size:12px;color:gray;">
            If you did not receive this payment or have any concerns, please contact us immediately.
        </p>

        @include('programs.partials.brand_footer')
    </div>
</div>
