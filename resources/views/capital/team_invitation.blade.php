<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Team Invitation'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $firstName }},</p>
        <p>You have been invited to join <strong>{{ $orgName }}</strong> on Tujitume as <strong>{{ str_replace('_', ' ', (string) $role) }}</strong>.</p>
        <p>Set your password to start. The link works for 7 days.</p>

        @include('programs.partials.brand_button', ['label' => 'Accept invitation'])

        @include('programs.partials.brand_footer')
    </div>
</div>
