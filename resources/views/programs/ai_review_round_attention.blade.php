<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'AI Review Needs Attention'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>AI review for <strong>{{ $round_name }}</strong> in {{ $program_title }} needs your attention.</p><p>{{ $issue }}</p><p>You can score affected applications yourself.</p>

        @include('programs.partials.brand_button', ['label' => 'Open AI Review'])

        @include('programs.partials.brand_footer')
    </div>
</div>
