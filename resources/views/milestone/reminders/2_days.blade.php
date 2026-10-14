<!-- Email 2: 2 Days Before Deadline -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    @include('programs.partials.brand_header', ['title' => 'Milestone at risk — 2 days left!'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> is currently at risk, with <strong>${{ $current_funding }}</strong> funded out of <strong>${{ $funding_goal }}</strong> and only <strong>{{ $days_left }} days</strong> remaining.</p>
        <p>Funds remain safe in escrow. To prevent this milestone from failing, please contribute immediately.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $invest_link }}" target="_blank" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);transition:background-color 0.3s ease-in-out;"
               onmouseover="this.style.backgroundColor='#139647';" onmouseout="this.style.backgroundColor='#14532d';">
                Fund Immediately
            </a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
