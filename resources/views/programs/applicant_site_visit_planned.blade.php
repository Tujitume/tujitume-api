<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Site Visit Planned'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p>A site visit has been planned for <strong>{{ $checkpoint_name ?? 'your checkpoint' }}</strong> under <strong>{{ $program_title }}</strong>.</p>
        <p>Someone from the program team or a site visit officer will visit. Open the checkpoint to see the details and to prepare.</p>

        @include('programs.partials.brand_button', ['label' => 'View Checkpoint'])

        @include('programs.partials.brand_footer')
    </div>
</div>
