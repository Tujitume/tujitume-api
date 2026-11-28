<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Non-Compliant'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p>

        <p>The milestone <strong>{{ $milestone_name }}</strong> of <strong>{{ $business_name }}</strong> passed its deadline at {{ $progress }}% progress, so it is now marked <strong>non-compliant</strong>. Your investors can see this.</p>
        <p>Update the milestone's progress, or submit a revised plan (RMEP) explaining how and when it will be completed.</p>

        @include('programs.partials.brand_button', ['label' => 'Open your milestones', 'url' => $reviewUrl])

        @include('programs.partials.brand_footer')
    </div>
</div>
