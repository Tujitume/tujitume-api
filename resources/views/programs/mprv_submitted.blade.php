<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'MPRV Submitted'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $business_name }}</strong> has submitted MPRV for Milestone <strong>{{ $milestone_number }}</strong>.</p>
        
        <p>Please review the submitted documents and take appropriate action.</p>

        @include('programs.partials.brand_button', ['label' => 'Review MPRV'])

        @include('programs.partials.brand_footer')
    </div>
</div>
