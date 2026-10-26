<div style="max-width:1024px;margin:auto;margin-top:4rem;background:#fff;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,.1);overflow:hidden">
    @include('programs.partials.brand_header', ['title' => 'Funding Plan Ready for Your Review'])
    <div style="padding:20px;font-size:14px;line-height:1.6">
        <p>Hi {{ $recipientName }},</p>
        <p>The program owner sent the funding plan for <strong>{{ $program_title }}</strong> for your review.</p>
        <p>Open your deal room to review the milestones and respond.</p>
        @include('programs.partials.brand_button', ['label' => 'Review Funding Plan'])
        @include('programs.partials.brand_footer')
    </div>
</div>
