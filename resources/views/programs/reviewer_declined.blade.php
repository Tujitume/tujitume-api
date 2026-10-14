<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Reviewer Declined Assignment'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $reviewer_name }}</strong> declined the review assignment for <strong>{{ $round_name }}</strong> in {{ $program_title }}.</p>

        <p>Please assign another reviewer so the round can proceed.</p>

        @include('programs.partials.brand_button', ['label' => 'Assign Another Reviewer'])

        @include('programs.partials.brand_footer')
    </div>
</div>
