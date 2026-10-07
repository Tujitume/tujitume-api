<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Bid Confirmed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Congratulations!</p>
        <p>Your bid to invest in <strong>{{ $business_name }}</strong> has been confirmed.</p>

        @if($type == 'Monetary')
        <p>This investment process has been confirmed, and the project will now proceed to the next phase: <strong>Milestone Progression (In Progress)</strong>. Here's what to expect:</p>
        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;">&#10003; <strong>Milestone coordination:</strong> payments will be coordinated between the platform, the Business Owner and the Investor as milestones are completed.</p>
            <p style="margin:0.5rem 0;">&#10003; <strong>Dashboard updates:</strong> all milestone progress and related financials will be updated in your project dashboard in real time.</p>
            <p style="margin:0.5rem 0;">&#10003; <strong>Support:</strong> if you have any questions, our support team is here to help.</p>
            <p style="margin:0.5rem 0;">&#10003; Please watch out for milestone completion emails, as the progress of your investment depends on your review.</p>
            <p style="margin:0.5rem 0;">&#10003; You can request a local project manager to supervise the project.</p>
        </div>

        <p>Proceed to progress with the milestone work?</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.api_url') }}agreeToProgressWithMilestone/{{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Proceed with Milestones</a>
        </div>

        <p style="margin-top:1.5rem;">If you require a project manager, please <a href="{{ config('app.app_url') }}dashboard?b_idToVWPM={{ $bid_id }}" style="color:#14532d;font-weight:700;">click here</a>. (Investors with assets must have a project manager.)</p>
        @else
        <p>Please request a Project Manager to proceed with this investment. (Investors with assets must have a project manager.)</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}dashboard?b_idToVWPM={{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Project Manager Verify</a>
            <a href="{{ config('app.app_url') }}dashboard?b_idToVWBO={{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Business Owner Verify</a>
            <a href="{{ config('app.api_url') }}CancelAssetBid/{{ $bid_id }}/confirm" style="background-color:#9f1239;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Cancel</a>
        </div>
        @endif

        @include('programs.partials.brand_footer')
    </div>
</div>
