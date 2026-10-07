<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Round Closing Soon'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $round_name }}</strong> for <strong>{{ $program_title }}</strong> closes in <strong>{{ $days_left }} days</strong>! ⏰</p>
        
        <p>Don't miss this opportunity. Submit your application before the deadline.</p>

        @include('programs.partials.brand_button', ['label' => 'Complete Application'])

        @include('programs.partials.brand_footer')
    </div>
</div>
