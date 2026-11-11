<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'AI Reports Ready'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>{{ $count }} AI report(s) are ready for <strong>{{ $round_name }}</strong> in {{ $program_title }}.</p><p>They are suggestions to help you. You still score each application yourself.</p>

        @include('programs.partials.brand_button', ['label' => 'Open Applications'])

        @include('programs.partials.brand_footer')
    </div>
</div>
