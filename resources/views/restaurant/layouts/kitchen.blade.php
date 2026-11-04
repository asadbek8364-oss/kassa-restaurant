<!doctype html>
<html lang="ru">
<head>
@include('restaurant.partials.head')
</head>
{{-- Кухня (KDS): пассивный настенный дисплей — светлая тема, крупный шрифт, только просмотр, никаких кнопок. --}}
<body class="app-kitchen" data-app="{{ $app }}">
<div class="kds-shell" id="shell" hidden>
  <header class="kds-top">
    <div class="kds-brand"><span class="kds-name" id="brand-name">Кухня</span><span class="kds-stop" id="stop-list"></span></div>
    <div class="kds-alerts" id="alerts" aria-live="polite"></div>
    <div class="kds-clock" id="clock"></div>
  </header>
  <main class="kds-board" id="view">@yield('content')</main>
</div>
<main class="login-view" id="login" hidden></main>
@include('restaurant.partials.overlays')
</body>
</html>
