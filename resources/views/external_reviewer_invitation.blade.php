<!doctype html>
<html lang="en">

<body style="margin:0;background:#f5f7f6;font-family:Arial,sans-serif;color:#1f2937">
    <div style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden">
        <div style="background:#14532d;padding:24px;color:#fff">
            <h1 style="margin:0;font-size:24px">You’re invited to join Tujitume</h1>
        </div>
        <div style="padding:28px">
            <p>Hello {{ $teamMember->first_name }},</p>
            <p>{{ $inviter->first_name }} has invited you to join Tujitume as a {{ str_replace('_', ' ', $role) }}.</p>
            <p>Accept the invitation and create your password using the button below.</p>
            <p style="margin:28px 0">
                <a href="{{ $invitationUrl }}" style="display:inline-block;padding:12px 20px;background:#14532d;color:#fff;text-decoration:none;border-radius:6px">Accept invitation</a>
            </p>
            <p style="font-size:13px;color:#6b7280">This invitation expires {{ $expiresAt->toDayDateTimeString() }}. If you were not expecting it, you can ignore this email.</p>
        </div>
    </div>
</body>

</html>