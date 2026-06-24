<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <!-- Header -->
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Milestone Continuation RMEP</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">

        <!-- Body -->
        <p>Hi {{$boName}},</p>
        <p>This milestone is in Continuation flow. The system requires you to <strong>upload your RMEP documents</strong> for the milestone <strong>{{$milestoneName}}</strong> to continue milestone progress.</p>

        <p>Please provide the minimum required progress evidence:</p>


        <div style="text-align:center;margin-top:2rem;">
            <a href="{{$reviewUrl}}" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Upload Progress Now</a>
        </div>

        <!-- Footer -->
        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>The Tujitume Team</p>
        </div>
    </div>
</div>
