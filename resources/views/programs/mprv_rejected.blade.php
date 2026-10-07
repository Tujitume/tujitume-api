<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'MPRV Requires Changes'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Your MPRV for Milestone <strong>{{ $milestone_number }}</strong> requires revision.</p>
        
        <div style="background-color:#fff3cd;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0;"><strong>Reason:</strong> {{ $reason }}</p>
        </div>
        
        <p>Please address the feedback and resubmit your MPRV.</p>

        @include('programs.partials.brand_button', ['label' => 'Revise MPRV'])

        @include('programs.partials.brand_footer')
    </div>
</div>
