<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Confirm your organization wallet withdrawal'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $name }},</p>

        <p>You asked to move <strong>KES {{ $amount }}</strong> from the <strong>{{ $organization }}</strong> organization wallet to your own My Wallet.</p>

        <p>Enter this code on the withdrawal screen to confirm it is you:</p>

        <div style="text-align:center;margin:1.5rem 0;">
            <span style="display:inline-block;background-color:#f0fdf4;color:#14532d;border:2px dashed #14532d;border-radius:0.75rem;padding:0.9rem 2rem;font-size:2rem;font-weight:800;letter-spacing:0.4em;">{{ $code }}</span>
        </div>

        <p style="text-align:center;color:#dc2626;font-weight:700;">This code expires in {{ $minutes }} minutes.</p>

        <p style="font-size:13px;color:#6b7280;">If you did not ask for this, do not share the code; no money moves without it. Tell your organization's owner and change your password.</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
