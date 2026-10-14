<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Budget Ready'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Budget has been completed for Milestone <strong>{{ $milestone_number }}</strong>.</p>
        
        <p>You're now ready to submit your MPRV!</p>

        @include('programs.partials.brand_button', ['label' => 'Submit MPRV'])

        @include('programs.partials.brand_footer')
    </div>
</div>
