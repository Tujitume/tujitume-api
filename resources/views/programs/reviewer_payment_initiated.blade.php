<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Initiated'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>Your payment for work completed on <strong>{{ $round_name }}</strong> in {{ $program_title }} has been initiated.</p>

<<<<<<< HEAD
        <p><strong>Payment Amount:</strong> KES {{ $fee }}</p>
=======
        <p><strong>Payment Amount:</strong> {{ $currency ?? config('app.currency', 'KES') }} {{ number_format((float) $fee, 2) }}</p>
>>>>>>> 9612db9565bcc9813954b0be96afc8f129e0b9f1

        <p>The program owner is processing your payment. You can track the payment status from your dashboard.</p>

        @include('programs.partials.brand_button', ['label' => 'Track Payment'])

        @include('programs.partials.brand_footer')
    </div>
</div>
