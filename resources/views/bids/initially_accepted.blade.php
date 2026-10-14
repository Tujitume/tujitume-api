<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Bid Accepted'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Congratulations!</p>
        <p>Your bid to invest in <strong>{{ $business_name }}</strong> has been accepted.</p>

        @if($type == 'Monetary')
        <p><strong>Next steps:</strong> you may now complete the remaining 75% of the payment to proceed to the first milestone.</p>

        @php
            $listing_id = base64_encode(base64_encode($id));
            $p = '_0A_';
            $i = '_X1_';
            $amount = base64_encode(base64_encode($amount));
            $bid_id = base64_encode(base64_encode($bid_id));
            $uniqid = base64_encode(hexdec(uniqid()));
            $encoded_id_amount = $uniqid . $p . $amount . $i . $bid_id;
            $encoded_id_amount = base64_encode(base64_encode($encoded_id_amount));
        @endphp
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}listing/?string={{ $encoded_id_amount }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Pay Now</a>
        </div>
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
