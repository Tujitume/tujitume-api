<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Review Required: Business Owner Has Submitted RMEP Documents'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $investorName }},</p>
        <p>The business owner has submitted the required documents for milestone <strong>{{ $milestoneName }}</strong>.</p>
        <p>Please review and approve or request corrections.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Review Documents</a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
