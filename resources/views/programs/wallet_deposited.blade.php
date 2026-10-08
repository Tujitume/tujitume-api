<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Funds Deposited'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $amount }}</strong> has been deposited to the <strong>{{ $program_title }}</strong> wallet.</p>
        
        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> {{ $amount }}</p>
            <p style="margin:0.5rem 0;"><strong>New Balance:</strong> {{ $new_balance }}</p>
        </div>

        @include('programs.partials.brand_button', ['label' => 'View Wallet'])

        @include('programs.partials.brand_footer')
    </div>
</div>
