<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'You Have a New Bid'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $owner_name }},</p>
        <p><strong>{{ $investor_name }}</strong> made a {{ strtolower($type) }} bid on <strong>{{ $business_name }}</strong>{{ $amount ? ' of '.$amount : '' }}.</p>
        <p>Open your business bids to review it and accept or decline.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
