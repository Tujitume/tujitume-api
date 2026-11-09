<div style="max-width:720px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;font:15px/1.6 Arial,sans-serif;color:#222">
    @include('programs.partials.brand_header', ['title' => 'Application received'])
    <div style="padding:24px">
        <p>Hello {{ $startup_name }},</p>
        <p>Your application to <strong>{{ $program_title }}</strong> was submitted successfully.</p>
        <p>Your application reference: <strong>{{ $application_uid }}</strong></p>
        <p>Use the button below to track your application. You will be asked to sign in or create an account so it appears under My Applications.</p>
        @include('programs.partials.brand_button', ['label' => 'Track Your Application', 'url' => $tracking_url])
        @include('programs.partials.brand_footer')
    </div>
</div>
