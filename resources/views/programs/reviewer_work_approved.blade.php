<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Work Approved! 🎉'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>Your {{ $order_type }} work for <strong>{{ $program_title }}</strong> has been approved by the program owner.</p>

        <p>Payment of <strong>USD {{ $fee }}</strong> is now being processed and will be transferred to your wallet shortly.</p>

        <p>You can track the payment status from your dashboard.</p>

        @include('programs.partials.brand_button', ['label' => 'View Order'])

        @include('programs.partials.brand_footer')
    </div>
</div>
