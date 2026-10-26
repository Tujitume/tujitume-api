<div style="max-width:1024px;margin:auto;margin-top:4rem;background:#fff;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,.1);overflow:hidden">
    @include('programs.partials.brand_header', ['title' => 'Applicant Agreed to Verification Requests'])
    <div style="padding:20px;font-size:14px;line-height:1.6">
        <p>Hi {{ $recipientName }},</p>
        <p><strong>{{ $business_name }}</strong> agreed to the {{ $verification_type }} verification requests for <strong>{{ $milestone_title }}</strong>.</p>
        <p>{{ $request_count }} request(s) were acknowledged.</p>
        @include('programs.partials.brand_button', ['label' => 'Review Agreement'])
        @include('programs.partials.brand_footer')
    </div>
</div>
