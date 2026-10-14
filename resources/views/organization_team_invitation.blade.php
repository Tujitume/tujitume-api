<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'You’re Invited'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $teamMember->first_name }},</p>

        <p>{{ $inviter->first_name }} has invited you to join <strong>{{ $organization->name }}</strong> as a {{ str_replace('_', ' ', $role) }}.</p>

        <p>Accept the invitation and create your password using the button below.</p>

        @include('programs.partials.brand_button', ['label' => 'Accept Invitation', 'url' => $invitationUrl])

        <p style="margin-top:1.5rem;font-size:13px;color:#6b7280;">This invitation expires {{ $expiresAt->toDayDateTimeString() }}. If you were not expecting it, you can ignore this email.</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
