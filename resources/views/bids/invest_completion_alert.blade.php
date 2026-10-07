<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Completion'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Your investment in <strong>{{ $business_name }}</strong> is done, as all milestones are completed. Please review the business.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}listing/{{ $business_id }}?review_popup=true" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Review Business</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
