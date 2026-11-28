<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Final Milestone Completed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Dear Customer,</p>
        <p>All milestones for the service <strong>{{ $business }}</strong> have been completed by the service owner.</p>

        <p><strong>Next steps:</strong> please confirm if you would like to release the final payment. Once confirmed, the remaining payment will be transferred to the service owner.</p>

        <p>Open the milestone in Tujitume to approve it and release the final payment, or to request changes.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $link ?? config('app.app_url') }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Review Final Milestone</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
