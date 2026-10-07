<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Bid Cancelled'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Unfortunately, your bid has been cancelled. The investor <strong>{{ $investor }}</strong> has decided to rescind their investment commitment to <strong>{{ $business_name }}</strong>.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
