<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Equipment Release Cancel Confirmation'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Are you sure you want to cancel this equipment release? If you choose <strong>OK</strong>, your investment commitment to <strong>{{ $business_name }}</strong> will be cancelled and you'll be redirected to Tujitume.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.api_url') }}CancelAssetBid/{{ $bid_id }}/ok" style="background-color:#9f1239;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">OK, Cancel Release</a>
            <a href="{{ config('app.app_url') }}equipmentRelease/{{ $business_owner }}/{{ $manager }}/{{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Release Equipment</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
