<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Reviewer Work Submitted'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $reviewer_name }}</strong> has submitted their {{ $order_type }} work for <strong>{{ $program_title }}</strong>.</p>

        @if ($order_type === 'round_review')
            <p>Round: <strong>{{ $round_name }}</strong></p>
        @endif

        <p>Please review the submitted work and provide feedback if needed.</p>

        @include('programs.partials.brand_button', ['label' => 'Review Work'])

        @include('programs.partials.brand_footer')
    </div>
</div>
