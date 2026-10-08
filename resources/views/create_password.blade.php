<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Tujitume Join Request'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>You have been requested to manage <strong>{{ $org }}</strong> as <strong>{{ ucfirst($role) }}</strong> by {{ $o_email }}.</p>
        <p>Accept this invitation to create your new password. After that you can log in with this email (<strong>{{ $email }}</strong>) and your new password.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}create-password?jmru_Eid={{ base64_encode($email) }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Accept Invitation</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
