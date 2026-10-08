<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Can Be Fulfilled'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>A milestone for your business <strong>{{ $business_name }}</strong> can now be fulfilled. You can start reviewing and accepting bids as well.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
