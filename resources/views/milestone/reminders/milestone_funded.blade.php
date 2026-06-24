<!-- Email 5: Milestone Funded -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    <div style="background-color:#14532d;padding:1rem;text-align:center;color:#fff;">
        <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png')}}" alt="Company Logo" style="height:3rem;margin:0 auto"/>
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Milestone fully funded — execution begins!</h1>
    </div>
    <div style="padding:20px;font-size:12px;line-height:1.8;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> has been fully funded with <strong>${{ $total_raised }}</strong>.</p>
        <p>Execution begins <strong>today</strong>. You can view the plan and updates below.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $execution_link }}" target="_blank" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">View Execution Plan</a>
        </div>
        <div style="margin-top:2rem;color:gray;font-size:12px;">
            <p><img src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png')}}" alt="Logo" style="height:3rem;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:2rem;"/></p>
            <p style="font-weight:600;">Best regards,<br/><div>The Tujitume Team</div></p>
        </div>
    </div>
</div>
