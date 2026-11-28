<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Counter Offer Received'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The provider of <strong>{{ $business_name }}</strong> answered your offer of KES {{ number_format((float) $your_offer, 2) }} with <strong>KES {{ number_format((float) $counter_price, 2) }}</strong>.</p>
        @if(!empty($note))<p>Their note: {{ $note }}</p>@endif

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
