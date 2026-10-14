{{-- Email header. Expects $title; $brand (App\Service\Notification\EmailBrand) falls back to Tujitume's look. --}}
@php
    $brand = $brand ?? \App\Service\Notification\EmailBrand::defaults();
    $gradient = 'background-color:' . $brand['accent'] . ';background-image:linear-gradient(135deg,' . $brand['accent'] . ' 0%,' . $brand['accent_dark'] . ' 100%);';
@endphp
<div style="padding:14px 14px 0;">
<div style="border-radius:18px;overflow:hidden;">
@if (!empty($brand['logo_data']))
    <div style="background-color:#ffffff;padding:1.75rem 1rem 1.25rem;text-align:center;border-bottom:1px solid #eef0ee;">
        <img src="{{ $message->embedData($brand['logo_data'], 'logo', $brand['logo_mime']) }}" alt="{{ $brand['name'] }}" style="height:3.5rem;width:auto;margin:0 auto;border-radius:12px;" />
    </div>
    <div style="{{ $gradient }}padding:2rem 1.5rem;text-align:center;color:{{ $brand['on_accent'] }};">
        <h1 style="font-size:1.9rem;line-height:1.25;font-weight:800;letter-spacing:-0.01em;margin:0;">{{ $title }}</h1>
        <div style="width:3.5rem;height:4px;border-radius:2px;background-color:{{ $brand['on_accent'] }};opacity:0.55;margin:1rem auto 0;"></div>
    </div>
@else
    <div style="{{ $gradient }}padding:2rem 1.5rem;text-align:center;color:{{ $brand['on_accent'] }};">
        @if (!empty($brand['custom']))
            <div style="font-size:0.85rem;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;opacity:0.85;">{{ $brand['name'] }}</div>
        @else
            <img src="{{ $message->embed(public_path('images/Email/EmailWhite.png')) }}" alt="Tujitume" style="height:3.25rem;width:auto;margin:0 auto;" />
        @endif
        <h1 style="font-size:1.9rem;line-height:1.25;font-weight:800;letter-spacing:-0.01em;margin:1rem 0 0;">{{ $title }}</h1>
        <div style="width:3.5rem;height:4px;border-radius:2px;background-color:{{ $brand['on_accent'] }};opacity:0.55;margin:1rem auto 0;"></div>
    </div>
@endif
</div>
</div>
