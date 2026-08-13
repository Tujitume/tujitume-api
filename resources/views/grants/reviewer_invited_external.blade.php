<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <!-- Header -->
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">You've Been Invited to Review</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>You have been requested to review grant applications for <strong>{{ $grant_title }}</strong> on Tujitume — a grant and investment funding platform.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Grant:</strong> {{ $grant_title }}</p>
            <p style="margin:0.5rem 0;"><strong>Round:</strong> {{ $round_name }}</p>
            @if(!empty($max_apps))
            <p style="margin:0.5rem 0;"><strong>Applications to Review:</strong> {{ $max_apps }}</p>
            @endif
            @if(!empty($expertise_tags))
            <p style="margin:0.5rem 0;"><strong>Expertise Required:</strong> {{ implode(', ', $expertise_tags) }}</p>
            @endif
        </div>

        <p>An account has been created for you. Please use the credentials below to set your password and log in:</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Email:</strong> {{ $email }}</p>
            <p style="margin:0.5rem 0;"><strong>Temporary Password:</strong> {{ $temp_password }}</p>
        </div>

        <p>For security, please reset your password after logging in for the first time by clicking the button below:</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.url') }}/create-password?jmru_Eid={{ base64_encode($email) }}"
                style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">
                Set My Password & Login
            </a>
        </div>

        <p style="margin-top:1.5rem;font-size:12px;color:gray;">
            If you did not expect this invitation or have any questions, please contact us at
            <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>
        </p>

        <!-- Footer -->
        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br />The Tujitume Team</p>
        </div>
    </div>
</div>