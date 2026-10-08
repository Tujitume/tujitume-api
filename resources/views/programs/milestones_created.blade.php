<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestones Created'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $milestone_count }}</strong> milestones have been created for your program <strong>{{ $program_title }}</strong>.</p>
        
        <p>You can now start working on your first milestone.</p>

        @include('programs.partials.brand_button', ['label' => 'View Milestones'])

        @include('programs.partials.brand_footer')
    </div>
</div>
