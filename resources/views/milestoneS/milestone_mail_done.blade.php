<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Completed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        @php
            $random = $rep_id + 51;
            $random2 = $booker_id + 47;
            $rep_id = base64_encode($rep_id . '.' . $random);
            $booker_id = base64_encode($booker_id . '.' . $random2);
            $s_id = base64_encode(base64_encode($s_id));
        @endphp
        <p>Dear Customer,</p>
        <p>The service owner has marked milestone <strong>{{ $name }}</strong> for the service <strong>{{ $business }}</strong> as complete.</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;"><strong>Milestone:</strong> {{ $name }}</p>
            <p style="margin:0.5rem 0;"><strong>Status:</strong> Completed</p>
        </div>

        <p><strong>Next steps:</strong> please confirm if you would like to proceed to the next milestone.</p>
        <ul style="padding-left:1.25rem;">
            <li>Once confirmed, the payment for this milestone will be released to the service owner.</li>
            <li><strong>Review Milestone</strong> will set the milestone back to “In Progress” and the work will continue.</li>
        </ul>

        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;">If no action is taken within <strong>7 days</strong> from the date of this email, the payment for this milestone will be automatically released to the service owner as per our auto-approval policy.</p>
            <p style="margin:0.5rem 0;">If you have any concerns about the milestone, please <strong>contact us</strong> before the auto-approval period ends.</p>
        </div>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.api_url') }}agreeToMileS/{{ $rep_id }}/{{ $booker_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Confirm and Proceed</a>
            <a href="{{ config('app.api_url') }}reviewMilestoneS/{{ $rep_id }}/{{ $booker_id }}" style="background-color:#374151;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Review Milestone</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
