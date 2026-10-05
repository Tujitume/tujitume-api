<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Supplier Added'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>Supplier <strong>{{ $supplier_name }}</strong> has been added to Milestone <strong>{{ $milestone_number }}</strong>.</p>

        @include('programs.partials.brand_button', ['label' => 'View Suppliers'])

        @include('programs.partials.brand_footer')
    </div>
</div>
