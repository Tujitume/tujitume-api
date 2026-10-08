<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Round Scoring Complete'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>All applications for <strong>{{ $round_name }}</strong> in {{ $program_title }} have been scored.</p>

        <p>Please finalize this round and start the next round.</p>

        @include('programs.partials.brand_button', ['label' => 'Finalize Round'])

        @include('programs.partials.brand_footer')
    </div>
</div>
