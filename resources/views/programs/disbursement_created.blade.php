<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Payment Initiated'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>A payment of <strong>{{ $amount }}</strong> has been initiated to the Supplier:<strong>{{ $supplier_name }}</strong>.</p>
        
        <p>Payment is currently processing.</p>

        @include('programs.partials.brand_button', ['label' => 'Track Payment'])

        @include('programs.partials.brand_footer')
    </div>
</div>
