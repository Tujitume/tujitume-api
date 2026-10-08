<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'You’re Verified'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $name }},</p>

        <p>Great news! {{ $intro }}</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0 0 0.5rem;font-weight:700;color:#14532d;">&#10003; Verified</p>
            <p style="margin:0.25rem 0;"><strong>Name:</strong> {{ $legalName }}</p>
            <p style="margin:0.25rem 0;"><strong>Verification:</strong> {{ $verificationType }}</p>
            <p style="margin:0.25rem 0;"><strong>Verified on:</strong> {{ $verifiedAt }}</p>
        </div>

        <p>{{ $next }}</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
