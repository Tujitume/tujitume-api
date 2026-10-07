<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Bid Approval Reminder'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Dear {{ $owner }},</p>
        <p>You have pending bids awaiting action on your dashboard. Please review them.</p>

        <div style="background-color:#f3f4f6;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Business Name:</strong> {{ $business }}</p>
        </div>
        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Important:</strong> if no action is taken within <strong>30 days</strong>, bids will be automatically cancelled as per Tujitume policy.</p>
        </div>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}dashboard/investment-bids" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Review Bids</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
