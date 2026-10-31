<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | Tujitume</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 300) }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 300) }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:url" content="{{ $url }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="canonical" href="{{ $url }}">
    <style>
        body { margin: 0; font: 16px/1.6 system-ui, sans-serif; color: #17211b; background: #f6faf7; }
        main { max-width: 760px; margin: 8vh auto; padding: 32px; background: white; border-radius: 18px; box-shadow: 0 12px 40px #173d2612; }
        img { max-width: 180px; max-height: 110px; object-fit: contain; }
        a { display: inline-block; margin-top: 16px; padding: 12px 20px; color: white; background: #087443; border-radius: 999px; text-decoration: none; font-weight: 650; }
    </style>
</head>
<body>
<main>
    @if($program->organization_logo)<img src="{{ $program->organization_logo }}" alt="Organization logo">@endif
    <h1>{{ $title }}</h1>
    <p>{{ \Illuminate\Support\Str::limit(strip_tags($description), 1000) }}</p>
    @if($program->funder_name)<p><strong>Program by:</strong> {{ $program->funder_name }}</p>@endif
    <a href="{{ $url }}">View program on Tujitume</a>
</main>
</body>
</html>
