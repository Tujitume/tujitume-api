<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Awaiting Payment'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $owner }},</p>

        <p>An investment in <strong>{{ $business }}</strong> is accepted and waiting for payment. Open your dashboard to see it and follow up with the investor.</p>

        @include('programs.partials.brand_button', ['label' => 'Open your dashboard'])

        @include('programs.partials.brand_footer')
    </div>
</div>
