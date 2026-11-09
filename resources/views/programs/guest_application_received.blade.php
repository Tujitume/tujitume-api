<div style="max-width:720px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;font:15px/1.6 Arial,sans-serif;color:#222">
    @include('programs.partials.brand_header', ['title' => 'Application received'])
    <div style="padding:24px">
        <p>Hello {{ $startup_name }},</p>
        <p>Your application to <strong>{{ $program_title }}</strong> was submitted successfully.</p>
        <p>You can sign up with this same email address to track and manage your application.</p>
        @include('programs.partials.brand_button', ['label' => 'Sign up', 'action_url' => $signup_url])
        @include('programs.partials.brand_footer')
    </div>
</div>
