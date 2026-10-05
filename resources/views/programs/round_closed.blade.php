<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Round Closed'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $round_name }}</strong> for <strong>{{ $program_title }}</strong> is now closed.</p>
        
        <p>Thank you to everyone who applied. Results will be announced soon.</p>

        @include('programs.partials.brand_button', ['label' => 'View Status'])

        @include('programs.partials.brand_footer')
    </div>
</div>
