@php($assets = $assets ?? '/restaurant')
<div class="modal-layer" id="modal" hidden>
  <div class="modal-backdrop" data-act="modalClose"></div>
  <div class="modal" role="dialog" aria-modal="true" id="modal-box"></div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<iframe id="print-frame" class="print-frame" title="Печать чека" tabindex="-1" aria-hidden="true"></iframe>
<noscript><p class="noscript">Для работы приложения включите JavaScript.</p></noscript>
@if($demo ?? false)
{{-- демо-версия: вместо сервера — эмулятор API в браузере --}}
<script src="{{ $assets }}/demo-server.js?v={{ $v }}"></script>
@endif
<script src="{{ $assets }}/common.js?v={{ $v }}"></script>
<script src="{{ $assets }}/{{ $app }}.js?v={{ $v }}"></script>
