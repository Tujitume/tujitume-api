<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Site Visit Work Rejected'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>
        <p>Your site visit work for <strong>{{ $program_title }}</strong> was rejected by the program owner.</p>
        @if (!empty($reason))<p>Reason: {{ $reason }}</p>@endif
        @include('programs.partials.brand_button', ['label' => 'View Order'])
        @include('programs.partials.brand_footer')
    </div>
</div>
