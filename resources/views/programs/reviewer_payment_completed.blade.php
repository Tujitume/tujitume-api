<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Received 💰'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>Your payment has been successfully received!</p>

        <p><strong>Payment Details:</strong></p>
        <ul style="margin:1rem 0;">
            <li><strong>Program:</strong> {{ $program_title }}</li>
            <li><strong>Amount:</strong> {{ $amount }} {{ $currency }}</li>
            <li><strong>Date:</strong> {{ now()->format('M d, Y') }}</li>
        </ul>

        <p>The funds have been transferred to your LIPR wallet. Thank you for your excellent work!</p>

        @include('programs.partials.brand_button', ['label' => 'View Payment'])

        @include('programs.partials.brand_footer')
    </div>
</div>
