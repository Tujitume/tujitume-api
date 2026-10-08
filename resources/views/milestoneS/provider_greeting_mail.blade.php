<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Thanks for Booking'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi <strong>{{ $customer }}</strong>,</p>
        <p>My name is <strong>{{ $s_provider }}</strong>. Thank you for booking my service, <strong>{{ $business }}</strong>. I’m excited to work with you and will be in touch shortly with the next steps.</p>

        <p style="margin-top:1.5rem;">If you have any questions, please contact us at <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>. Thank you for choosing Tujitume!</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
