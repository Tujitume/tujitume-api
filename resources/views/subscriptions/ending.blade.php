{{-- "Your plan ends soon" (SubscriptionReminder). Expects $first_name, $plan, $ends, $consequence, $renew_href. --}}
<div style="max-width:720px;margin:auto;margin-top:2rem;background-color:white;border-radius:1.25rem;border:1px solid #e5e7eb;overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Your plan ends soon'])

    <div style="padding:20px 24px;font-size:14px;line-height:1.65;color:#1f2937;">
        <p>Hi {{ $first_name }},</p>
        <p>Your Tujitume {{ $plan }} ends on <strong>{{ $ends }}</strong>.</p>
        <p>{{ $consequence }}</p>

        <div style="text-align:center;margin:2rem 0 1rem;">
            <a href="{{ $renew_href }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;">Renew plan</a>
        </div>
        <p style="font-size:12px;color:#6b7280;word-break:break-all;">If the button does not work, copy this address into your browser: {{ $renew_href }}</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
