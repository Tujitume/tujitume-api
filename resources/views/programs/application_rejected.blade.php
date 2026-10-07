<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Application Update'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Thank you for applying to <strong>{{ $program_title }}</strong>.</p>
        
        <p>After careful consideration, we regret to inform you that your application was not selected at this time.</p>
        
        <p>We encourage you to apply for future opportunities and wish you all the best.</p>

        @include('programs.partials.brand_button', ['label' => 'Browse Other Programs'])

        @include('programs.partials.brand_footer')
    </div>
</div>
