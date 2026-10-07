<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Pre Release Documents Required'])
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p>
        <p>Investor <strong>{{ $investorName }}</strong> has requested the following documents before milestone funds can be released:</p>
        <ul>
            @foreach($documents ?? [] as $group)
                @if(is_iterable($group))
                    @foreach($group as $item)
                        <li>{{ ucwords(str_replace('_', ' ', $item)) }}</li>
                    @endforeach
                @endif
            @endforeach
        </ul>
        <p>Upload them in your dashboard to proceed.</p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $dashboardUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Upload Documents</a>
        </div>
        @include('programs.partials.brand_footer')
    </div>
</div>
