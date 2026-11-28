<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Approved: Payment On Its Way'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p><strong>{{ $customer_name }}</strong> approved <strong>{{ $milestone }}</strong> of <strong>{{ $business_name }}</strong>.</p>
        <p><strong>KES {{ number_format((float) $amount, 2) }}</strong> is on its way to your wallet.@if(!empty($next)) The next milestone, {{ $next }}, can start now.@endif</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
