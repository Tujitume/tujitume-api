<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Request to Verify With a Project Manager'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The Business Owner of <strong>{{ $business_name }}</strong> will not be able to verify, so the Investor has to request a Project Manager to verify, or cancel altogether.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}dashboard?b_idToVWPM={{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Request a Project Manager</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
