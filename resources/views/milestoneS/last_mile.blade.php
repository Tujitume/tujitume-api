<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Final Milestone Completed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Dear Customer,</p>
        <p>All milestones for the service <strong>{{ $business }}</strong> have been completed by the service owner.</p>

        <p><strong>Next steps:</strong> please confirm if you would like to release the final payment. Once confirmed, the remaining payment will be transferred to the service owner.</p>

        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;">If no action is taken within <strong>7 days</strong> from the date of this email, the final payment will be automatically released to the service owner.</p>
        </div>

        @php
            $random = $rep_id + 51;
            $random2 = $booker_id + 47;
            $rep_id = base64_encode($rep_id . '.' . $random);
            $booker_id = base64_encode($booker_id . '.' . $random2);
            $s_id = base64_encode(base64_encode($s_id));
        @endphp
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.api_url') }}agreeToMileS/{{ $rep_id }}/{{ $booker_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Release Final Payment</a>
            <a href="{{ config('app.app_url') }}service-details/{{ $s_id }}?review_popup=true" style="background-color:#374151;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Review Service</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
