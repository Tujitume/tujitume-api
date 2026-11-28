<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Verification Received'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $name }},</p>

        <p>Thank you. We have received your verification and our team is reviewing it.</p>

        <div style="background-color:#f8fafc;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #334155;">
            <p style="margin:0 0 0.5rem;font-weight:700;color:#334155;">Under review</p>
            <p style="margin:0.25rem 0;"><strong>Name:</strong> {{ $legalName }}</p>
            <p style="margin:0.25rem 0;"><strong>Submitted on:</strong> {{ $submittedAt }}</p>
        </div>

        <p>We will email you as soon as it is approved, or if anything needs to change. You can check its status at any time.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
