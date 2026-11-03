<meta charset="utf-8">
<title>{{ $meta['name'] }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="description" content="{{ $meta['description'] }}">
<meta name="theme-color" content="{{ $meta['theme'] }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $meta['short'] }}">
<meta name="apple-mobile-web-app-status-bar-style" content="{{ $app === 'kitchen' ? 'black' : 'default' }}">
<meta name="robots" content="noindex">
<link rel="manifest" href="/{{ $app }}/manifest.webmanifest">
<link rel="icon" href="/restaurant/icons/{{ $app }}.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/restaurant/icons/{{ $app }}-192.png">
<link rel="stylesheet" href="/restaurant/restaurant.css?v={{ $v }}">
