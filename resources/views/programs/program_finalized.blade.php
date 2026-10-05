<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Program Complete'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $program_title }}</strong> has been finalized.</p>
        
        <p><strong>{{ $awarded_count }}</strong> businesses were successfully funded through this program program.</p>
        
        <p>Thank you for your participation!</p>

        @include('programs.partials.brand_button', ['label' => 'View Summary'])

        @include('programs.partials.brand_footer')
    </div>
</div>
