<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => $title])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $recipientName }},</p>

        <p>{{ $body }}</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Organization:</strong> {{ $organization->name }}</p>
            <p style="margin:0.5rem 0;"><strong>Role:</strong> {{ $role }}</p>
            <p style="margin:0.5rem 0;"><strong>Changed by:</strong> {{ $actorName }}</p>
        </div>

        @if (!empty($invitationUrl))
            @include('programs.partials.brand_button', ['label' => 'Accept Invitation', 'url' => $invitationUrl])
            <p style="margin-top:1.5rem;font-size:13px;color:#6b7280;">This invitation expires {{ $expiresAt->toDayDateTimeString() }}.</p>
        @endif

        <p style="margin-top:1.5rem;font-size:13px;color:#6b7280;">If you think this is a mistake, please contact {{ $organization->name }}.</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
