<!doctype html>
<html lang="ru">
<head>
@include('restaurant.partials.head')
</head>
{{-- Админка: боковое меню разделов на планшете и компьютере, нижняя панель на телефоне. --}}
<body class="app-admin" data-app="{{ $app }}">
<div class="admin-shell" id="shell" hidden>
  <aside class="admin-side" aria-label="Разделы админки">
    <div class="brand">
      <img src="{{ $assets ?? '/restaurant' }}/icons/admin.svg" alt="" width="36" height="36">
      <div class="brand-text"><strong id="brand-name">Ресторан</strong><span id="brand-mode"></span></div>
    </div>
    <nav class="side-nav" id="side-nav"></nav>
    <div class="side-foot" id="side-foot"></div>
  </aside>
  <div class="admin-main">
    <header class="admin-top">
      <div class="top-title"><span class="eyebrow" id="eyebrow"></span><h1 id="title">Админка</h1></div>
      <div class="top-actions" id="top-actions"></div>
    </header>
    <div class="banner" id="banner" hidden></div>
    <main class="admin-view" id="view" tabindex="-1">@yield('content')</main>
  </div>
  <nav class="bottom-nav" id="bottom-nav" aria-label="Разделы"></nav>
</div>
<main class="login-view" id="login" hidden></main>
@include('restaurant.partials.overlays')
</body>
</html>
