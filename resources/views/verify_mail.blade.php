<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Verify Your Email'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Your Tujitume verification code is:</p>

        <div style="text-align:center;margin:1.5rem 0;">
            <span style="display:inline-block;background-color:#f0fdf4;color:#14532d;border:2px dashed #14532d;border-radius:0.75rem;padding:0.9rem 2rem;font-size:2rem;font-weight:800;letter-spacing:0.4em;">{{ $code }}</span>
        </div>

        <p style="text-align:center;color:#dc2626;font-weight:700;">This code expires in 10 minutes.</p>

        <p style="font-size:13px;color:#6b7280;">Please enter this code in the verification form to complete your process. If you didn't request this code, please ignore this email.</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
