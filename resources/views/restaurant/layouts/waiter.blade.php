<!doctype html>
<html lang="ru">
<head>
@include('restaurant.partials.head')
</head>
{{-- Официант: телефон (≈ 390–430 px) и планшет, крупные кнопки, всё в 2–3 касания. --}}
<body class="app-waiter" data-app="{{ $app }}">
<div class="waiter-shell" id="shell" hidden>
  <header class="w-top">
    <button class="w-back" id="back" data-act="back" aria-label="Назад" hidden></button>
    <div class="w-title"><span class="eyebrow" id="eyebrow"></span><h1 id="title">Зал</h1></div>
    <div class="w-net" id="net" hidden></div>
    <button class="w-who" id="who" data-act="account" aria-label="Сотрудник"></button>
  </header>
  <main class="w-view" id="view" tabindex="-1">@yield('content')</main>
  <footer class="w-bar" id="bar" hidden></footer>
</div>
<main class="login-view" id="login" hidden></main>
@include('restaurant.partials.overlays')
</body>
</html>
