<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'You Were Chosen as Project Manager'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $pmName }},</p>

        <p>The investors chose you as the Project Manager to audit <strong>{{ $milestoneName }}</strong> of <strong>{{ $boName }}</strong> (Mid-milestone verification).</p>
        <p>Open Project Manager Audits on your dashboard to review the evidence and send your report.</p>

        @include('programs.partials.brand_button', ['label' => 'Open the audit', 'url' => $reviewUrl])

        @include('programs.partials.brand_footer')
    </div>
</div>
