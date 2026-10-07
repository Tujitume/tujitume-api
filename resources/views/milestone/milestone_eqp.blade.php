<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Request to Invest With Equipment'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Investor <strong>{{ $inv_name }}</strong> (contact: {{ $inv_contact }}) wants to provide equipment for <strong>{{ $mile_name }}</strong>. Please contact them to proceed.</p>
        <p>If you require a Transaction Advisor, use the button below.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}services" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Find a Transaction Advisor</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
