<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Reversed'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Payment of <strong>{{ $amount }}</strong> to <strong>{{ $supplier_name }}</strong> has been reversed.</p>
        
        <p>Funds have been returned to the program wallet.</p>

        @include('programs.partials.brand_button', ['label' => 'View Details'])

        @include('programs.partials.brand_footer')
    </div>
</div>
