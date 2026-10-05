<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Marked as Done'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>A milestone status has changed to <strong>Done</strong>.</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;"><strong>Milestone Name:</strong> {{ $name }}</p>
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> {{ $amount }}</p>
            <p style="margin:0.5rem 0;"><strong>Service Name:</strong> {{ $business }}</p>
        </div>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
