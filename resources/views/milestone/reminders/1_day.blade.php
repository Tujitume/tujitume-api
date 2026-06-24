<!-- Email 3: 1 Day Before Deadline -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    <div style="background-color:#14532d;padding:1rem;text-align:center;color:#fff;">
        <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png')}}" alt="Company Logo" style="height:3rem;margin:0 auto"/>
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Final 24 hours to fund this milestone</h1>
    </div>
    <div style="padding:20px;font-size:12px;line-height:1.8;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> has only 24 hours left to reach its funding goal.</p>
        <span style="font-weight: bold;">{!! $funding_bar_html !!}% complete!</span>
        <p>Reminder: If funding is below 60%, this milestone will fail.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $funding_link }}" target="_blank" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;transition:background-color 0.3s ease-in-out;"
               onmouseover="this.style.backgroundColor='#139647';" onmouseout="this.style.backgroundColor='#14532d';">
                Fund Now
            </a>
        </div>
        <div style="margin-top:2rem;color:gray;font-size:12px;">
            <p><img src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png')}}" alt="Logo" style="height:3rem;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:2rem;"/></p>
            <p style="font-weight:600;">Best regards,<br/><div>The Tujitume Team</div></p>
        </div>
    </div>
</div>
