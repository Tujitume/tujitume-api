<div style="max-width:1024px;margin:auto;margin-top:4rem;background:#fff;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,.1);overflow:hidden">
    @include('programs.partials.brand_header', ['title' => 'Funding Plan Activated'])
    <div style="padding:20px;font-size:14px;line-height:1.6">
        <p>Hi {{ $recipientName }},</p>
        <p>Your funding plan for <strong>{{ $program_title }}</strong> is active. You can now review the milestones in your deal room.</p>
        @include('programs.partials.brand_button', ['label' => 'Open Funding Plan'])
        @include('programs.partials.brand_footer')
    </div>
</div>
