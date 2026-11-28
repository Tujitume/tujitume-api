<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'You Are Invited to Review Applications'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p>You were invited to review applications for <strong>{{ $program_title ?? 'a program' }}</strong>@if(!empty($round_name)), {{ $round_name }}@endif as a Business Coach.</p>
        <p>Accept the invitation to see the applications assigned to you and start scoring.</p>

        @include('programs.partials.brand_button', ['label' => 'Open the invitation'])

        @include('programs.partials.brand_footer')
    </div>
</div>
