<div style="max-width:1024px;margin:auto;margin-top:4rem;background:#fff;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,.1);overflow:hidden">
    @include('programs.partials.brand_header', ['title' => 'Verification Agreement Message'])
    <div style="padding:20px;font-size:14px;line-height:1.6">
        <p>Hi {{ $recipientName }},</p>
        <p>{{ $actor_name }} sent a message about {{ $verification_type }} for <strong>{{ $milestone_title }}</strong>:</p>
        <p style="white-space:pre-wrap">{{ $comment }}</p>
        @include('programs.partials.brand_button', ['label' => 'Open Agreement'])
        @include('programs.partials.brand_footer')
    </div>
</div>
