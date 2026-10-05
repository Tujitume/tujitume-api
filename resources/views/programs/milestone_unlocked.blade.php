<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Milestone Unlocked'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Congratulations! Milestone <strong>{{ $milestone_number }}</strong> is now unlocked! 🎉</p>
        
        <p>You can now begin work on this milestone.</p>

        @include('programs.partials.brand_button', ['label' => 'View Milestone'])

        @include('programs.partials.brand_footer')
    </div>
</div>
