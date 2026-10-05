<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Additional Information Needed for Milestone Final Approval'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p>
        <p>Milestone <strong>{{ $milestoneName }}</strong> has been finally rejected by investors, milestone is fully done, next milestone started.</p>

        <p>Please revise and resubmit final document.</p>

{{--        @if(!empty($reasons_list))--}}
{{--            <div style="margin-top:1rem;">--}}
{{--                <p style="font-weight:600;margin-bottom:0.5rem;">Reasons for Rejection:</p>--}}
{{--                <ul style="padding-left:1.2rem;margin:0;">--}}
{{--                    {!! $reasons_list !!}--}}
{{--                </ul>--}}
{{--            </div>--}}
{{--        @endif--}}

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Resubmit Documents</a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
