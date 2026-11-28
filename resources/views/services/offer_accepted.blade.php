<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Your Offer Was Accepted'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Your offer of <strong>KES {{ number_format((float) $agreed_price, 2) }}</strong> for <strong>{{ $business_name }}</strong> was accepted.</p>
        <p>Pay for the booking to start the work. The money is held by Tujitume and released to the provider milestone by milestone, as you approve each one.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
