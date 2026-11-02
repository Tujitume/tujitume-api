<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | Tujitume</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($ogDescription), 300) }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($ogDescription), 300) }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:url" content="{{ $previewUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="{{ $url }}">
    <style>
        :root { color-scheme: light; font: 16px/1.6 system-ui, sans-serif; color: #17211b; background: #f4f8f5; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { background: #087443; color: white; padding: 16px max(24px, calc((100% - 1040px) / 2)); }
        header a { color: inherit; font-weight: 700; text-decoration: none; }
        main { max-width: 1040px; margin: 32px auto; padding: 0 20px 48px; }
        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 22px; align-items: start; }
        .stack { display: grid; gap: 18px; }
        .card { background: white; border: 1px solid #e6eee8; border-radius: 16px; padding: 24px; box-shadow: 0 8px 28px #173d260a; }
        .hero { border-top: 5px solid #087443; }
        .brand { max-width: 160px; max-height: 90px; object-fit: contain; margin-bottom: 12px; }
        h1 { font-size: clamp(1.8rem, 4vw, 2.5rem); line-height: 1.15; margin: 8px 0; }
        h2 { font-size: 1.15rem; margin: 0 0 12px; }
        p { margin: 8px 0; white-space: pre-line; }
        .muted { color: #5a6a60; }
        .status { display: inline-block; border-radius: 999px; padding: 4px 11px; background: {{ $isOpen ? '#e6f5ec' : '#eef1ef' }}; color: {{ $isOpen ? '#087443' : '#536158' }}; font-size: .85rem; font-weight: 700; }
        .stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 22px; }
        .stat { padding: 14px; border-radius: 10px; background: #f5f8f6; }
        .stat strong, .stat span { display: block; }
        .stat span { color: #5a6a60; font-size: .84rem; }
        ul { padding-left: 20px; margin: 0; }
        li + li { margin-top: 6px; }
        .sidebar { position: sticky; top: 20px; }
        a.external { color: #087443; overflow-wrap: anywhere; }
        footer { text-align: center; color: #66756b; padding: 20px; }
        @media (max-width: 760px) { .layout { grid-template-columns: 1fr; } .sidebar { position: static; } main { margin-top: 18px; } .card { padding: 20px; } }
    </style>
</head>
<body>
<header><a href="https://tujitume.com">Tujitume</a></header>
<main>
    <div class="layout">
        <div class="stack">
            <section class="card hero">
                @if($program->organization_logo)<img class="brand" src="{{ $program->organization_logo }}" alt="{{ $program->funder_name ?: 'Program organization' }} logo">@endif
                <span class="status">{{ $isOpen ? 'Open for applications' : 'Applications closed' }}</span>
                <h1>{{ $title }}</h1>
                @if($program->funder_name)<p class="muted">Program by <strong>{{ $program->funder_name }}</strong></p>@endif
                @if($program->description ?? false)<p>{{ strip_tags($program->description) }}</p>@elseif($program->message ?? false)<p>{{ strip_tags($program->message) }}</p>@endif
                <div class="stats">
                    <div class="stat"><strong>{{ $program->currency }} {{ number_format((float) $program->total_program_amount, 2) }}</strong><span>Total program amount</span></div>
                    <div class="stat"><strong>{{ $program->currency }} {{ number_format((float) $program->funding_per_business, 2) }}</strong><span>Funding per business</span></div>
                    @if($program->max_awardees)<div class="stat"><strong>{{ number_format($program->max_awardees) }}</strong><span>Maximum awardees</span></div>@endif
                    @if($program->disbursement_type)<div class="stat"><strong>{{ \Illuminate\Support\Str::headline($program->disbursement_type) }}</strong><span>Disbursement type</span></div>@endif
                </div>
            </section>

            @if($program->eligibility_criteria)
                <section class="card"><h2>Eligibility criteria</h2><p>{{ $program->eligibility_criteria }}</p></section>
            @endif
            @if($program->impact_objectives)
                <section class="card"><h2>Impact objectives</h2><p>{{ $program->impact_objectives }}</p></section>
            @endif
            @if($program->evaluation_criteria)
                <section class="card"><h2>Evaluation criteria</h2><p>{{ $program->evaluation_criteria }}</p></section>
            @endif

            @php
                $profiles = [
                    'Program focus areas' => $program->program_focus,
                    'Social impact areas' => $program->social_impact_areas,
                    'Eligible regions' => $program->regions,
                    'Startup stage focus' => $program->startup_stage_focus,
                    'Bonus points' => $program->bonus_points,
                ];
                $profiles = array_filter($profiles, fn ($items) => is_array($items) && count($items));
            @endphp
            @if(count($profiles))
                <section class="card"><h2>Program profile</h2>
                    @foreach($profiles as $label => $items)
                        <p><strong>{{ $label }}:</strong> {{ collect($items)->map(fn ($item) => is_array($item) ? ($item['label'] ?? $item['name'] ?? '') : $item)->filter()->join(', ') }}</p>
                    @endforeach
                </section>
            @endif
        </div>

        <aside class="stack sidebar">
            <section class="card"><h2>Key dates</h2>
                @if($program->start_date)<p><strong>Program opens:</strong> {{ \Illuminate\Support\Carbon::parse($program->start_date)->format('j M Y') }}</p>@endif
                @if($program->application_deadline)<p><strong>Application deadline:</strong> {{ \Illuminate\Support\Carbon::parse($program->application_deadline)->format('j M Y') }}</p>@endif
                @if($program->end_date)<p><strong>Program ends:</strong> {{ \Illuminate\Support\Carbon::parse($program->end_date)->format('j M Y') }}</p>@endif
                @if($firstRound?->review_period_end)<p><strong>Review ends:</strong> {{ \Illuminate\Support\Carbon::parse($firstRound->review_period_end)->format('j M Y') }}</p>@endif
                @if($firstRound?->announcement_date)<p><strong>Announcement:</strong> {{ \Illuminate\Support\Carbon::parse($firstRound->announcement_date)->format('j M Y') }}</p>@endif
            </section>
            @php $documents = $firstRound?->required_documents ?: $program->required_documents; @endphp
            @if(is_array($documents) && count($documents))
                <section class="card"><h2>Required documents</h2><ul>
                    @foreach($documents as $document)
                        <li>{{ is_array($document) ? ($document['label'] ?? $document['name'] ?? 'Document') : $document }}</li>
                    @endforeach
                </ul></section>
            @endif
            @if($program->owner_website ?? false)
                <section class="card"><h2>Organization website</h2><a class="external" href="{{ $program->owner_website }}" rel="noopener noreferrer">{{ $program->owner_website }}</a></section>
            @endif
            @if($program->program_brief_pdf)
                <section class="card"><h2>Program brief</h2><a class="external" href="{{ \Illuminate\Support\Str::startsWith($program->program_brief_pdf, ['http://', 'https://']) ? $program->program_brief_pdf : asset($program->program_brief_pdf) }}" target="_blank" rel="noopener noreferrer">View program brief (PDF)</a></section>
            @endif
        </aside>
    </div>
</main>
<footer>Program opportunity shared on Tujitume</footer>
</body>
</html>
