
<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Final Approval Update Required</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p>

        <p>Please upload final execution documents for milestone <strong>{{ $milestoneName }}</strong> to fully complete the milestone.</p>
        <p>Upload your evidence such as following using your project dashboard.</p>

{{--        <ul>--}}
{{--            <li><strong>Progress Statement</strong> (required)</li>--}}
{{--            <li><strong>Progress Proof Types</strong> (system-controlled checklist):--}}
{{--                <ul>--}}
{{--                    <li>Photos</li>--}}
{{--                    <li>Short videos</li>--}}
{{--                    <li>Receipts / invoices</li>--}}
{{--                    <li>Work logs</li>--}}
{{--                    <li>Supplier confirmations</li>--}}
{{--                    <li>Screenshots of digital progress</li>--}}
{{--                </ul>--}}
{{--            </li>--}}
{{--            <li><strong>Timeline Forecast</strong> (required)</li>--}}
{{--            <li><strong>Challenges</strong> (optional)</li>--}}
{{--        </ul>--}}

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">Open Dashboard</a>
        </div>

        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>Tujitume Projects Team</p>
        </div>
    </div>
</div>
