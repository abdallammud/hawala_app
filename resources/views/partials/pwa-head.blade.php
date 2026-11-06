{{-- Makes the app installable (manifest + icons). Shared by every layout. --}}
<meta name="application-name" content="Bluestar">
<meta name="theme-color" content="#121e3b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Bluestar">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="sw-url" content="{{ asset('sw.js') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
