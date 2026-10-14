<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Program Closed'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $program_title }}</strong> is no longer accepting applications.</p>
        
        <p>The application period has ended. Review process will begin shortly.</p>

        @include('programs.partials.brand_button', ['label' => 'View Applications'])

        @include('programs.partials.brand_footer')
    </div>
</div>
