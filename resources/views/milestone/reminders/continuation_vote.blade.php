<!-- Email 4: Continuation Vote -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    <div style="background-color:#14532d;padding:1rem;text-align:center;color:#fff;">
        <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png')}}" alt="Company Logo" style="height:3rem;margin:0 auto"/>
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Action required — vote on milestone continuation</h1>
    </div>
    <div style="padding:20px;font-size:12px;line-height:1.8;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> has raised <strong>${{ $amount_raised }}</strong>.</p>
        <p>Business owner has submitted the revised milestone execution plan (RMEP).</p>
        <p>Time remaining: <strong>{{ $days_left }} days</strong></p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $stay_link }}" target="_blank" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;margin-right:0.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Stay Invested</a>
            <a href="{{ $refund_link }}" target="_blank" style="background-color:#9ca3af;color:white;padding:0.75rem 1.5rem;margin-left:0.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Refund Me</a>
        </div>
        <div style="margin-top:2rem;color:gray;font-size:12px;">
            <p><img src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png')}}" alt="Logo" style="height:3rem;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:2rem;"/></p>
            <p style="font-weight:600;">Best regards,<br/><div>The Tujitume Team</div></p>
        </div>
    </div>
</div>
