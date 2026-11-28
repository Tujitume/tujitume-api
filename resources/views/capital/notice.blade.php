{{-- A capital step told by email (accepted, not selected, release requested): a title, the message, and a button that opens the same page as the in-app notice --}}
<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => $title ?? 'Capital update'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi{{ ! empty($name) ? ' '.$name : '' }},</p>
        <p>{{ $body }}</p>
        @if(! empty($detail))
            <p style="color:#555;">{{ $detail }}</p>
        @endif

        @include('programs.partials.brand_button', ['label' => $button ?? 'Open in Tujitume'])

        @include('programs.partials.brand_footer')
    </div>
</div>
