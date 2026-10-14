{{-- Email footer. $brand (App\Service\Notification\EmailBrand) falls back to Tujitume's look. --}}
@php
    $brand = $brand ?? \App\Service\Notification\EmailBrand::defaults();
@endphp
<div style="margin-top:2.5rem;padding-top:1.25rem;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280;overflow:hidden;">
    @if (!empty($brand['logo_data']))
        <img src="{{ $message->embedData($brand['logo_data'], 'logo-footer', $brand['logo_mime']) }}" alt="{{ $brand['name'] }}" style="height:2.5rem;width:auto;float:left;margin-right:1rem;" />
    @elseif (empty($brand['custom']))
        <img src="{{ $message->embed(public_path('images/Email/EmailVertDark.png')) }}" alt="Tujitume" style="height:2.5rem;width:auto;float:left;margin-right:1rem;" />
    @endif
    <p style="margin:0;font-weight:600;color:#374151;">Best regards,<br/>The {{ $brand['name'] }} Team</p>
    <p style="margin:0.75rem 0 0;font-size:11px;color:#9ca3af;clear:both;">You are receiving this email because of your activity on {{ $brand['name'] }}. Please do not reply directly to this message.</p>
</div>
