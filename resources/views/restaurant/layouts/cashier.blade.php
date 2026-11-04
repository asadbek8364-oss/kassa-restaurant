<!doctype html>
<html lang="ru">
<head>
@include('restaurant.partials.head')
</head>
{{-- Кассир: зал и заказы как у официанта, плюс навынос и оплата через терминал; планшет на кассе или телефон. --}}
<body class="app-cashier" data-app="{{ $app }}">
<div class="waiter-shell" id="shell" hidden>
  <header class="w-top">
    <button class="w-back" id="back" data-act="back" aria-label="Назад" hidden></button>
    <div class="w-title"><span class="eyebrow" id="eyebrow"></span><h1 id="title">Касса</h1></div>
    <div class="w-net" id="net" hidden></div>
    <button class="w-who" id="who" data-act="account" aria-label="Сотрудник"></button>
  </header>
  <nav class="w-tabs" id="tabs" aria-label="Разделы кассы"></nav>
  <main class="w-view" id="view" tabindex="-1">@yield('content')</main>
  <footer class="w-bar" id="bar" hidden></footer>
</div>
<main class="login-view" id="login" hidden></main>
@include('restaurant.partials.overlays')
</body>
</html>
