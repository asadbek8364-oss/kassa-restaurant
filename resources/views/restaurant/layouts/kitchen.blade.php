<!doctype html>
<html lang="ru">
<head>
@include('restaurant.partials.head')
</head>
{{-- Кухня (KDS): полноэкранная тёмная доска, очень крупный шрифт, управление касанием. --}}
<body class="app-kitchen" data-app="{{ $app }}">
<div class="kds-shell" id="shell" hidden>
  <header class="kds-top">
    <div class="kds-brand"><strong id="brand-name">Кухня</strong><span class="kds-clock" id="clock"></span></div>
    <div class="kds-alerts" id="alerts"></div>
    <div class="kds-actions">
      <button class="kds-btn" data-act="stopList" id="stop-btn">Стоп-лист</button>
      <button class="kds-btn" data-act="sound" id="sound-btn" aria-pressed="false">Звук</button>
      <button class="kds-btn" data-act="fullscreen">На весь экран</button>
      <button class="kds-btn" data-act="account" id="who"></button>
    </div>
  </header>
  <main class="kds-board" id="view">@yield('content')</main>
</div>
<div class="kds-unlock" id="unlock" hidden>
  <button class="kds-unlock-btn" data-act="unlock">Нажмите, чтобы включить звук и начать</button>
</div>
<main class="login-view" id="login" hidden></main>
@include('restaurant.partials.overlays')
</body>
</html>
