<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Your Payment Is on the Way'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>A payment for <strong>{{ $supplier_name }}</strong> is now being processed.</p>
        <p>Payment amount: <strong>{{ $amount }}</strong></p>

        @if (!empty($payment_reference))
            <p>Payment reference: <strong>{{ $payment_reference }}</strong></p>
        @endif

        <p>We will notify you when the transfer is complete.</p>

        @include('programs.partials.brand_button', ['label' => 'View My Orders'])

        @include('programs.partials.brand_footer')
    </div>
</div>