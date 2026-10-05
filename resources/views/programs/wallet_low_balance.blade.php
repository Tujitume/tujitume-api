<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Low Wallet Balance'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>The wallet balance for <strong>{{ $program_title }}</strong> is running low.</p>
        
        <div style="background-color:#fff3cd;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Current Balance:</strong> {{ $balance }}</p>
            <p style="margin:0.5rem 0;"><strong>Reserved:</strong> {{ $reserved }}</p>
        </div>
        
        <p>Please deposit additional funds to continue disbursements.</p>

        @include('programs.partials.brand_button', ['label' => 'Deposit Funds'])

        @include('programs.partials.brand_footer')
    </div>
</div>
