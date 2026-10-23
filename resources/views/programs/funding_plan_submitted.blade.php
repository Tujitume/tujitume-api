<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Funding Plan Submitted'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>
        <p><strong>{{ $business_name }}</strong> submitted a funding plan for <strong>{{ $program_title }}</strong>.</p>
        <p>Review the milestones and funding setup in the deal room.</p>
        @include('programs.partials.brand_button', ['label' => 'Review Funding Plan'])
        @include('programs.partials.brand_footer')
    </div>
</div>
