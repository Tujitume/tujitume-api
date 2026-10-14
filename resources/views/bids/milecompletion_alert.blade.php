<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Completed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Milestone <strong>{{ $mile_name }}</strong> of the business <strong>{{ $business_name }}</strong> is done, and you can now review it with the entrepreneur.</p>
        <p>Do you want to continue to the next milestone?</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.api_url') }}agreeToNextmile/{{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Continue to Next Milestone</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
