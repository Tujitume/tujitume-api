<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Message'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p><strong>{{ $sender }}</strong> sent you a message:</p>

        <div style="margin:1rem 0 0;padding:1rem 1.25rem;background-color:#f3f4f6;border-left:4px solid {{ $brand['accent'] }};border-radius:12px;color:#111827;white-space:pre-line;">{{ $msg }}</div>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
