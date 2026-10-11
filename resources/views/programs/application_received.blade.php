<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Thank you for applying'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>Thank you for {{ !empty($round_submission) ? 'submitting your application for ' . $round_name . ' of' : 'applying to' }} <strong>{{ $program_title }}</strong>. We have received it{{ !empty($organization_name) ? ' at ' . $organization_name : '' }}.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Program:</strong> {{ $program_title }}</p>
            @if (!empty($business_name))
                <p style="margin:0.5rem 0;"><strong>Business:</strong> {{ $business_name }}</p>
            @endif
            @if (!empty($round_name))
                <p style="margin:0.5rem 0;"><strong>Round:</strong> {{ $round_name }}</p>
            @endif
            <p style="margin:0.5rem 0;"><strong>Received:</strong> {{ $submitted_at ?? now()->format('j M Y, H:i') }}</p>
        </div>

        <p><strong>What happens next</strong></p>
        <ul style="padding-left:1.2rem;margin:0.5rem 0 1rem;">
            <li>Once the round closes, the team reviews every application.</li>
            @if (!empty($announcement_date))
                <li>Results are expected on <strong>{{ $announcement_date }}</strong>.</li>
            @else
                <li>You will be told the result by email and in the app.</li>
            @endif
            <li>You can open the program at any time to check where things stand.</li>
        </ul>

        @include('programs.partials.brand_button', ['label' => 'View Program'])

        <p style="margin-top:1.5rem;font-size:13px;color:#6b7280;">You do not need to do anything else for now. Good luck!</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
