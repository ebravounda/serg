// Confirmaciones de acciones del panel (sin JavaScript en línea, compatible con la CSP)
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
}, true);
