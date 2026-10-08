<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Completed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>Payment of <strong>{{ $amount }}</strong> has been successfully completed to <strong>{{ $supplier_name }}</strong>.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Supplier:</strong> {{ $supplier_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> {{ $amount }}</p>
            <p style="margin:0.5rem 0;"><strong>Reference:</strong> {{ $payment_reference ?? 'N/A' }}</p>
        </div>

        @include('programs.partials.brand_button', ['label' => 'View Payment'])

        @include('programs.partials.brand_footer')
    </div>
</div>
