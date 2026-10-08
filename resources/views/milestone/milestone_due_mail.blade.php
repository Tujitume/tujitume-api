<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Due Alert'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Your milestone has <strong>{{ $d }} day(s)</strong> left.</p>

        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Milestone Name:</strong> {{ $name }}</p>
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> ${{ $amount }}</p>
        </div>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
