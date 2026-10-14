<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Round Update'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->

        {{-- CUSTOMISABLE: program owner body OR blade default --}}
        @if (!empty($custom_body))
        {!! $custom_body !!}
        @else

        <p>Hi {{ $recipientName }},</p>

        <p>Thank you for your participation in <strong>{{ $round_name }}</strong> of <strong>{{ $program_title }}</strong>.</p>

        <p>Unfortunately, you were not selected to advance to the next round.</p>

        <p>We appreciate your effort and encourage you to explore other opportunities on Tujitume.</p>

        @endif

        @include('programs.partials.brand_button', ['label' => 'Browse Programs'])

        @include('programs.partials.brand_footer')
    </div>
</div>