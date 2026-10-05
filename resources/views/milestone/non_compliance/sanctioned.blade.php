<!-- Email 4: Continuation Vote -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Sanctioned for Non-Compliance'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> of business <strong>{{ $business_name }}</strong>,
            sanctioned by platform, further milestones remain locked & refund will be initiated.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" target="_blank" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Review</a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
