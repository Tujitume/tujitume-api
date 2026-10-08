<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Advanced to Next Round'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        {{-- CUSTOMISABLE: program owner body OR blade default --}}
        @if (!empty($custom_body))
            {!! $custom_body !!}
        @else
            <p>Excellent news! You've advanced to <strong>{{ $round_name }}</strong> in <strong>{{ $program_title }}</strong>! 🎉</p>
            <p>Your application stood out among many submissions.</p>
            <p>Please prepare for the next round. You will receive further instructions shortly.</p>
        @endif

        @include('programs.partials.brand_button', ['label' => 'View Next Round'])

        @include('programs.partials.brand_footer')
    </div>
</div>
