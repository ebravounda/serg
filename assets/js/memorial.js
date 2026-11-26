(function () {
  // Importe personalizado: se muestra al elegir "Otro"
  var form = document.querySelector('.donate');
  if (form) {
    var custom = form.querySelector('[data-custom]');
    var input = form.querySelector('#custom');
    var sync = function () {
      var other = form.querySelector('input[name="amount"][value="otro"]').checked;
      custom.classList.toggle('is-visible', other);
      input.required = other;
      if (other) input.focus();
    };
    form.addEventListener('change', function (e) { if (e.target.name === 'amount') sync(); });
    custom.classList.toggle('is-visible', form.querySelector('input[value="otro"]').checked);
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.textContent = 'Redirigiendo al pago seguro…';
    });
    // Si el usuario vuelve con el botón "atrás", reactiva el botón
    window.addEventListener('pageshow', function () {
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = false;
      btn.textContent = 'Realiza tu donación';
    });
  }

  // Actualiza la barra cada minuto sin recargar
  var bar = document.querySelector('[data-bar]');
  if (!bar || !window.fetch) return;
  function refresh() {
    fetch('estado.php', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (p) {
        bar.style.width = p.percent + '%';
        bar.parentNode.setAttribute('aria-valuenow', p.percent);
        document.querySelector('[data-raised]').textContent = p.raised_text;
        document.querySelector('[data-goal]').textContent = p.goal_text;
        document.querySelector('[data-percent]').textContent = p.percent + '%';
        document.querySelector('[data-count]').textContent = p.count;
        document.querySelector('[data-count-label]').textContent = p.count === 1 ? 'aporte' : 'aportes';
      })
      .catch(function () {});
  }
  setInterval(function () { if (!document.hidden) refresh(); }, 60000);
})();
