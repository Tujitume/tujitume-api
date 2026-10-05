<!-- Email 5: Milestone Funded -->
<div style="max-width:1024px;margin:4rem auto;background:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;position:relative;">
    @include('programs.partials.brand_header', ['title' => 'Milestone fully funded — execution begins!'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>The milestone <strong>{{ $milestone_name }}</strong> has been fully funded with <strong>${{ $total_raised }}</strong>.</p>
        <p>Execution begins <strong>today</strong>. You can view the plan and updates below.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $execution_link }}" target="_blank" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">View Execution Plan</a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
