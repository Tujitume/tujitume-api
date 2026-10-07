<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Application Reviewed'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Your application for <strong>{{ $program_title }}</strong> has been reviewed by our evaluation team.</p>
        
        <p>You will be notified of the final decision soon.</p>

        @include('programs.partials.brand_button', ['label' => 'View Application'])

        @include('programs.partials.brand_footer')
    </div>
</div>
