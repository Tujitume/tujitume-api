<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Audit Requested'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>An audit has been requested for MPRV submission from <strong>{{ $business_name }}</strong> - Milestone <strong>{{ $milestone_number }}</strong>.</p>
        
        <p>Please review and provide your audit findings.</p>

        @include('programs.partials.brand_button', ['label' => 'Conduct Audit'])

        @include('programs.partials.brand_footer')
    </div>
</div>
