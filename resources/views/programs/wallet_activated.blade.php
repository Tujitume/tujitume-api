<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Wallet Activated'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p>The wallet for <strong>{{ $program_title }}</strong> is now active and ready for disbursements!</p>
        
        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Total Funds:</strong> {{ $total_amount }}</p>
            <p style="margin:0.5rem 0;"><strong>Status:</strong> Active</p>
        </div>
        
        <p>You can now process disbursements for approved milestones.</p>

        @include('programs.partials.brand_button', ['label' => 'View Wallet'])

        @include('programs.partials.brand_footer')
    </div>
</div>
