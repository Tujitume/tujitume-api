<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Failed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>Unfortunately, the payment to reviewer <strong>{{ $reviewer_name }}</strong> for {{ $program_title }} failed.</p>

        <p>The program owner has been notified and will retry the payment shortly. Please ensure your LIPR wallet is active and properly configured.</p>

        <p>If you continue to experience issues, please contact our support team.</p>

        @include('programs.partials.brand_button', ['label' => 'View Details'])

        @include('programs.partials.brand_footer')
    </div>
</div>
