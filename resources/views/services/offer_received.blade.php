<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Offer On Your Service'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p><strong>{{ $customer_name }}</strong> offered <strong>KES {{ number_format((float) $offered_price, 2) }}</strong> for <strong>{{ $business_name }}</strong> (your price KES {{ number_format((float) $original_price, 2) }}).</p>
        @if(!empty($note))<p>Their note: {{ $note }}</p>@endif
        <p>Accept it to book the service at that price, or decline it.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
