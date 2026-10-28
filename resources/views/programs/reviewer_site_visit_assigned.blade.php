<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Site Visit Assignment'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>
        <p>You have been assigned a site visit for <strong>{{ $program_title }}</strong>.</p>
        @if (!empty($checkpoint_name))<p>Checkpoint: <strong>{{ $checkpoint_name }}</strong></p>@endif
        @if (!empty($fee) && (float) $fee > 0)<p>Reviewer fee: <strong>{{ $fee }} {{ $currency ?? 'KES' }}</strong></p>@endif
        <p>Open your reviewer orders to accept or decline this assignment.</p>
        @include('programs.partials.brand_button', ['label' => 'View Site Visit'])
        @include('programs.partials.brand_footer')
    </div>
</div>
