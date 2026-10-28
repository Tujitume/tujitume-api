<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Site Visit Assignment Accepted'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>
        <p><strong>{{ $reviewer_name }}</strong> accepted the site visit assignment for <strong>{{ $program_title }}</strong>.</p>
        @include('programs.partials.brand_button', ['label' => 'View Orders'])
        @include('programs.partials.brand_footer')
    </div>
</div>
