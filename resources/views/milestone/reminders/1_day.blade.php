<!-- Email 3: 1 Day Before Deadline -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    @include('programs.partials.brand_header', ['title' => 'Final 24 hours to fund this milestone'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> has only 24 hours left to reach its funding goal.</p>
        <span style="font-weight: bold;">{!! $funding_bar_html !!}% complete!</span>
        <p>Reminder: If funding is below 60%, this milestone will fail.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $funding_link }}" target="_blank" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);transition:background-color 0.3s ease-in-out;"
               onmouseover="this.style.backgroundColor='#139647';" onmouseout="this.style.backgroundColor='#14532d';">
                Fund Now
            </a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
