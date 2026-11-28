<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Verification Needs Changes'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $name }},</p>

        <p>We reviewed your verification and need a few changes before we can approve it.</p>

        <div style="background-color:#fef2f2;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #b91c1c;">
            <p style="margin:0 0 0.5rem;font-weight:700;color:#b91c1c;">What to change</p>
            <p style="margin:0.25rem 0;white-space:pre-line;">{{ $reason }}</p>
        </div>

        <p>Your saved details are kept. Open your verification, make the changes and submit it again.</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
