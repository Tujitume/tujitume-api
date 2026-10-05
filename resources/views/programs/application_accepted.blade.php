<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Application Accepted'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Your application to <strong>{{ $program_title }}</strong> has been accepted! 🎉</p>
        
        <p>We were impressed by your proposal and look forward to working with you.</p>
        
        <p>Next steps will be communicated to you shortly.</p>

        @include('programs.partials.brand_button', ['label' => 'View Application'])

        @include('programs.partials.brand_footer')
    </div>
</div>
