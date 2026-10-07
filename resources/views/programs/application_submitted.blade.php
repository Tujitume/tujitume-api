<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Application Received'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Great news! A new application has been submitted to <strong>{{ $program_title }}</strong>.</p>
        
        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Business Name:</strong> {{ $business_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Submitted:</strong> {{ $submitted_at ?? now()->format('M d, Y') }}</p>
        </div>

        <p>You can review the application details in your program dashboard.</p>

        @include('programs.partials.brand_button', ['label' => 'Review Application'])

        @include('programs.partials.brand_footer')
    </div>
</div>
