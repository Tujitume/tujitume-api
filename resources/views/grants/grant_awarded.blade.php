<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <!-- Header -->
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Awardees Selected! 🎉</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>All application rounds for <strong>{{ $grant_title }}</strong> have been completed and awardees have been finalized. Funding setup is now ready to begin.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Grant:</strong> {{ $grant_title }}</p>
            <p style="margin:0.5rem 0;"><strong>Awardees Selected:</strong> {{ $awarded_count }}</p>
            <p style="margin:0.5rem 0;"><strong>Total Funding:</strong> {{ $total_amount }}</p>
        </div>

        <p>The selected businesses are now ready to begin their funding setup. Please log in to review and approve their milestone plans.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.url') }}/grant/manage/{{ $grant_id }}" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Go to Funding Setup</a>
        </div>

        <!-- Footer -->
        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>The Tujitume Team</p>
        </div>
    </div>
</div>
