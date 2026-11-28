{{-- Every email of the demo-request flow (DemoRequestMailer). Text is escaped; $body is plain text whose line breaks
     are kept (an admin-written proposal). Expects $title and optionally:
     $greeting, $paragraphs (list of strings), $details (label => value), $body, $button_href + $button_label,
     $secondary_text + $secondary_href + $secondary_label (a smaller second link), $footnote. --}}
<div style="max-width:720px;margin:auto;margin-top:2rem;background-color:white;border-radius:1.25rem;border:1px solid #e5e7eb;overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => $title])

    <div style="padding:20px 24px;font-size:14px;line-height:1.65;color:#1f2937;">
        @if(!empty($greeting))
            <p>{{ $greeting }}</p>
        @endif

        @foreach(($paragraphs ?? []) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach

        @if(!empty($body))
            <div style="margin:1rem 0;">{!! nl2br(e($body)) !!}</div>
        @endif

        @if(!empty($details))
            <div style="background-color:#f3f4f6;padding:1rem 1.25rem;border-radius:0.75rem;margin:1.5rem 0;">
                @foreach($details as $label => $value)
                    @continue($value === null || $value === '')
                    <p style="margin:0.35rem 0;"><strong>{{ $label }}:</strong> {{ $value }}</p>
                @endforeach
            </div>
        @endif

        @if(!empty($button_href) && !empty($button_label))
            <div style="text-align:center;margin:2rem 0 1rem;">
                <a href="{{ $button_href }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;">{{ $button_label }}</a>
            </div>
            <p style="font-size:12px;color:#6b7280;word-break:break-all;">If the button does not work, copy this address into your browser: {{ $button_href }}</p>
        @endif

        @if(!empty($secondary_href) && !empty($secondary_label))
            <p style="text-align:center;margin:1.5rem 0 0.5rem;font-size:13px;color:#374151;">
                {{ $secondary_text ?? '' }}
                <a href="{{ $secondary_href }}" style="color:#14532d;font-weight:700;">{{ $secondary_label }}</a>
            </p>
        @endif

        @if(!empty($footnote))
            <p style="font-size:12px;color:#6b7280;">{{ $footnote }}</p>
        @endif

        @include('programs.partials.brand_footer')
    </div>
</div>
