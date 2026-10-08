<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Initiated'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>Your payment for work completed on <strong>{{ $round_name }}</strong> in {{ $program_title }} has been initiated.</p>

        <p><strong>Payment Amount:</strong> USD {{ $fee }}</p>

        <p>The program owner is processing your payment. You can track the payment status from your dashboard.</p>

        @include('programs.partials.brand_button', ['label' => 'Track Payment'])

        @include('programs.partials.brand_footer')
    </div>
</div>
