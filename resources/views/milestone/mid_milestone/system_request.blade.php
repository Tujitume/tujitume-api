
<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Mid-Milestone Update Required'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p>

        <p>To proceed with releasing the remaining 25% of milestone <strong>{{ $milestoneName }}</strong> funds, please submit your required mid-milestone progress update.</p>
        <p>Upload your evidence such as following using your project dashboard.</p>

        <ul>
            <li><strong>Progress Statement</strong> (required)</li>
            <li><strong>Progress Proof Types</strong> (system-controlled checklist):
                <ul>
                    <li>Photos</li>
                    <li>Short videos</li>
                    <li>Receipts / invoices</li>
                    <li>Work logs</li>
                    <li>Supplier confirmations</li>
                    <li>Screenshots of digital progress</li>
                </ul>
            </li>
            <li><strong>Timeline Forecast</strong> (required)</li>
            <li><strong>Challenges</strong> (optional)</li>
        </ul>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Open Dashboard</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
