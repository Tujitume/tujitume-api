<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Round Scored by AI'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $round_name }}</strong> in {{ $program_title }} has been scored by AI.</p><p>Please review the scores and finalize the round.</p>

        @include('programs.partials.brand_button', ['label' => 'Review and Finalize'])

        @include('programs.partials.brand_footer')
    </div>
</div>
