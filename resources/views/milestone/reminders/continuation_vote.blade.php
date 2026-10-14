<!-- Email 4: Continuation Vote -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    @include('programs.partials.brand_header', ['title' => 'Action required — vote on milestone continuation'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> has raised <strong>${{ $amount_raised }}</strong>.</p>
        <p>Business owner has submitted the revised milestone execution plan (RMEP).</p>
        <p>Time remaining: <strong>{{ $days_left }} days</strong></p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $stay_link }}" target="_blank" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Stay Invested</a>
            <a href="{{ $refund_link }}" target="_blank" style="background-color:#9ca3af;color:white;padding:0.75rem 1.5rem;margin-left:0.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Refund Me</a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
