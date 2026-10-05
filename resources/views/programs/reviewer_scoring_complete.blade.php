<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Scoring Complete'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $reviewer_name }}</strong> has completed scoring all applications for <strong>{{ $round_name }}</strong> in {{ $program_title }}.</p>

        <p>Please review their work and finalize the round when satisfied.</p>

        @include('programs.partials.brand_button', ['label' => 'Review Scores'])

        @include('programs.partials.brand_footer')
    </div>
</div>
