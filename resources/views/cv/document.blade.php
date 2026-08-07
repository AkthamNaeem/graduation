<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 42px; }
        body { color: #20242a; font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; line-height: 1.45; }
        h1 { color: #111827; font-size: 24px; margin: 0 0 2px; }
        .headline { color: #475569; font-size: 12px; margin-bottom: 8px; }
        .contact, .links { color: #475569; font-size: 9px; margin-bottom: 4px; }
        .contact span, .links span { display: inline-block; margin-{{ $direction === 'rtl' ? 'left' : 'right' }}: 12px; }
        .section { margin-top: 17px; }
        h2 { border-bottom: 1px solid #cbd5e1; color: #0f172a; font-size: 12px; letter-spacing: .5px; margin: 0 0 8px; padding-bottom: 3px; text-transform: uppercase; }
        .item { margin-bottom: 10px; page-break-inside: avoid; }
        .item-title { color: #111827; font-size: 11px; font-weight: bold; }
        .item-meta { color: #64748b; font-size: 9px; margin-top: 1px; }
        .description { margin-top: 3px; white-space: pre-line; }
        .skill { background: #eef2f7; border-radius: 3px; display: inline-block; margin: 0 4px 5px 0; padding: 3px 7px; }
        a { color: #334155; text-decoration: none; }
    </style>
</head>
<body>
    @if($cv['name'])<h1>{{ $cv['name'] }}</h1>@endif
    @if($cv['headline'])<div class="headline">{{ $cv['headline'] }}</div>@endif

    <div class="contact">
        @foreach($cv['contact'] as $value)
            @if($value)<span>{{ $value }}</span>@endif
        @endforeach
    </div>
    @if($cv['links'] !== [])
        <div class="links">
            @foreach($cv['links'] as $link)
                <span>{{ $link['label'] }}: <a href="{{ $link['url'] }}">{{ $link['url'] }}</a></span>
            @endforeach
        </div>
    @endif

    @if($cv['summary'])
        <div class="section">
            <h2>{{ __('cv_document.sections.summary') }}</h2>
            <div class="description">{{ $cv['summary'] }}</div>
        </div>
    @endif

    @if($cv['experiences'] !== [])
        <div class="section">
            <h2>{{ __('cv_document.sections.experience') }}</h2>
            @foreach($cv['experiences'] as $experience)
                <div class="item">
                    <div class="item-title">{{ collect([$experience['title'], $experience['company']])->filter()->join(' — ') }}</div>
                    <div class="item-meta">
                        {{ collect([$experience['start_date'], $experience['is_current'] ? __('cv_document.present') : $experience['end_date'], $experience['location']])->filter()->join(' · ') }}
                    </div>
                    @if($experience['description'])<div class="description">{{ $experience['description'] }}</div>@endif
                </div>
            @endforeach
        </div>
    @endif

    @if($cv['education'] !== [])
        <div class="section">
            <h2>{{ __('cv_document.sections.education') }}</h2>
            @foreach($cv['education'] as $education)
                <div class="item">
                    <div class="item-title">{{ collect([$education['degree'], $education['field_of_study']])->filter()->join(', ') }}</div>
                    <div>{{ $education['institution'] }}</div>
                    <div class="item-meta">{{ collect([$education['start_date'], $education['end_date']])->filter()->join(' — ') }}</div>
                    @if($education['description'])<div class="description">{{ $education['description'] }}</div>@endif
                </div>
            @endforeach
        </div>
    @endif

    @if($cv['skills'] !== [])
        <div class="section">
            <h2>{{ __('cv_document.sections.skills') }}</h2>
            @foreach($cv['skills'] as $skill)<span class="skill">{{ $skill }}</span>@endforeach
        </div>
    @endif
</body>
</html>
