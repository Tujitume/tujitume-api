<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Checkpoint Submitted'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p><strong>{{ $actor_name ?? 'Someone' }}</strong> submitted the monitoring checkpoint <strong>{{ $checkpoint_name }}</strong> for <strong>{{ $program_title }}</strong>.</p>
        <p>Open it to read the submission, then verify it or ask for changes.</p>

        @include('programs.partials.brand_button', ['label' => 'Review Checkpoint'])

        @include('programs.partials.brand_footer')
    </div>
</div>
