<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Milestone Funds Approved</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p> <p> We’re pleased to inform you that a
            @if( $release_type == 'pre' )
                <strong>pre-release (75%)</strong>
            @else
                <strong>mid milestone release (25%)</strong>
            @endif
            amount for the milestone
            <strong>{{ $milestoneTitle }}</strong> has been successfully approved and released. </p>
        <p> <strong>Milestone Amount:</strong> {{ $amount }}<br/>
            <strong>Released Amount:</strong> {{ $released_amount }} </p>
        <p> You can track this release and view milestone progress directly from your dashboard. </p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $dashboardUrl }}" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;"> Go to Dashboard </a>
        </div>

        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>Tujitume Support</p>
        </div>
    </div>
</div>
