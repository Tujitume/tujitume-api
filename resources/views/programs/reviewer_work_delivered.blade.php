<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <!-- Header -->
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Reviewer Work Submitted</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $reviewer_name }}</strong> has submitted their {{ $order_type }} work for <strong>{{ $program_title }}</strong>.</p>

        @if ($order_type === 'round_review')
            <p>Round: <strong>{{ $round_name }}</strong></p>
        @endif

        <p>Please review the submitted work and provide feedback if needed.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.url') }}/overview/programs/reviewer-orders/{{ $order_id }}" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Review Work</a>
        </div>

        <!-- Footer -->
        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>The Tujitume Team</p>
        </div>
    </div>
</div>
