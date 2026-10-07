<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Program Published'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Your program <strong>{{ $program_title }}</strong> has been successfully published!</p>
        
        <p>It will become visible to applicants on the start date.</p>

        @include('programs.partials.brand_button', ['label' => 'View Program'])

        @include('programs.partials.brand_footer')
    </div>
</div>
