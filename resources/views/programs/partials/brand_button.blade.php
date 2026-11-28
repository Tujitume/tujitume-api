{{-- Email call-to-action. $label (else $action_label) names it. Goes to $url when given (token / sign-up links),
     otherwise to $action_url, which the sender sets from the notification's link. Hidden without a destination.
     $locale (en|sw, default en) picks the language of the sign-in note. --}}
@php
    $brand = $brand ?? \App\Service\Notification\EmailBrand::defaults();
    $buttonLabel = $label ?? ($action_label ?? 'Open in ' . $brand['name']);
    $buttonUrl = $url ?? ($action_url ?? null);
    $signInNote = \App\Service\Notification\ProgramEmailDefaults::chrome($locale ?? 'en', $brand['name'])['sign_in'];
@endphp
@if (!empty($buttonUrl))
    <div style="text-align:center;margin-top:2rem;">
        <a href="{{ $buttonUrl }}" style="background-color:{{ $brand['accent'] }};color:{{ $brand['on_accent'] }};display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">{{ $buttonLabel }}</a>
        @if (empty($url))
            <p style="margin:0.9rem 0 0;font-size:12px;color:#9ca3af;">{{ $signInNote }}</p>
        @endif
    </div>
@endif
