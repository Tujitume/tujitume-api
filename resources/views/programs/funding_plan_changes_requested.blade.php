<div style="max-width:1024px;margin:auto;margin-top:4rem;background:#fff;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,.1);overflow:hidden">
    @include('programs.partials.brand_header', ['title' => 'Changes Requested on Funding Plan'])
    <div style="padding:20px;font-size:14px;line-height:1.6">
        <p>Hi {{ $recipientName }},</p>
        <p>The program owner requested changes to your funding plan for <strong>{{ $program_title }}</strong>.</p>
        <p><strong>Notes:</strong> {{ $revision_notes }}</p>
        <p>{{ $checklist_count }} checklist item(s) were included.</p>
        @include('programs.partials.brand_button', ['label' => 'Review Requested Changes'])
        @include('programs.partials.brand_footer')
    </div>
</div>
