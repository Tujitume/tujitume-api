<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Claim your Tujitume account'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName ?? '' }},</p>
        <p><strong>{{ $organization_name ?? 'A program team' }}</strong> now manages <strong>{{ $program_title }}</strong> on Tujitume, and your application is already there.</p>
        <p>Create your password to claim your account and follow your program from here.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $action_url }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;">Claim account</a>
        </div>
        <p style="margin-top:1.5rem;color:#6b7280;">This link works once and expires in 7 days.</p>
        @include('programs.partials.brand_footer')
    </div>
</div>
