<!-- Email 1: 7 Days Before Deadline -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    @include('programs.partials.brand_header', ['title' => 'Milestone is entering final week — help us get funded!'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> currently has raised <strong>${{ $current_funding }}</strong> out of <strong>${{ $funding_goal }}</strong>.</p>
        <p>Remember: sequential gating applies and all funds remain safely in escrow until the milestone is fully funded.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $invest_link }}" target="_blank" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);transition:background-color 0.3s ease-in-out;"
                onmouseover="this.style.backgroundColor='#139647';" onmouseout="this.style.backgroundColor='#14532d';">
                Invest Now
            </a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
