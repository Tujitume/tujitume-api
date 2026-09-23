<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(public_path('images/Email/EmailWhite.png')) }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Reviewer Declined Assignment</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $reviewer_name }}</strong> declined the review assignment for <strong>{{ $round_name }}</strong> in {{ $program_title }}.</p>

        <p>Please assign another reviewer so the round can proceed.</p>

        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(public_path('images/Email/EmailVertDark.png')) }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>The Tujitume Team</p>
        </div>
    </div>
</div>
