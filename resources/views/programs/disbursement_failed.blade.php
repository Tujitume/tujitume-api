<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Failed'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Payment to <strong>{{ $supplier_name }}</strong> failed.</p>
        
        <div style="background-color:#f8d7da;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #dc3545;">
            <p style="margin:0;"><strong>Reason:</strong> {{ $reason }}</p>
        </div>
        
        <p>Please review and retry the payment.</p>

        @include('programs.partials.brand_button', ['label' => 'Review Payment'])

        @include('programs.partials.brand_footer')
    </div>
</div>
