(function () {
  var doc = document.documentElement;

  // ---------- Importe personalizado: se muestra al elegir "Otro" ----------
  var form = document.querySelector('.donate');
  if (form) {
    var custom = form.querySelector('[data-custom]');
    var input = form.querySelector('#custom');
    var btn = form.querySelector('button[type="submit"]');
    var btnLabel = btn.querySelector('span') || btn;
    var original = btnLabel.textContent;
    var isOther = function () { return form.querySelector('input[name="amount"][value="otro"]').checked; };
    var sync = function (focus) {
      var other = isOther();
      custom.classList.toggle('is-visible', other);
      input.required = other;
      if (other && focus) input.focus();
    };
    form.addEventListener('change', function (e) { if (e.target.name === 'amount') sync(true); });
    sync(false);
    // Aviso en español si falta el apellido
    var donor = form.querySelector('#donor_name');
    if (donor) {
      var check = function () {
        donor.setCustomValidity(/\S+\s+\S/.test(donor.value.trim()) ? '' : 'Escribe tu nombre y apellido (ej. Juan Pérez)');
      };
      donor.addEventListener('input', check);
      // vista previa de cómo aparecerá el nombre
      var preview = form.querySelector('[data-preview]');
      var fmt = function (v) {
        return v.trim().replace(/\s+/g, ' ').toLowerCase().replace(/(^|[\s-])(\S)/g, function (m, a, b) { return a + b.toUpperCase(); })
          .replace(/ (De|Del|La|Las|Los|Y) /g, function (m) { return m.toLowerCase(); });
      };
      donor.addEventListener('input', function () { if (preview) preview.textContent = fmt(donor.value) || 'tu nombre'; });
      donor.addEventListener('invalid', check);
      check();
    }
    var radios = form.querySelectorAll('input[name="show_name"]');
    radios.forEach(function (r) {
      r.addEventListener('invalid', function () { r.setCustomValidity('Elige si quieres que tu nombre aparezca en la web'); });
      r.addEventListener('change', function () { radios.forEach(function (x) { x.setCustomValidity(''); }); });
    });
    form.addEventListener('submit', function () {
      btn.disabled = true;
      btnLabel.textContent = 'Abriendo el pago seguro…';
    });
    // Al volver con "atrás" desde Stripe, reactiva el botón
    window.addEventListener('pageshow', function () {
      btn.disabled = false;
      btnLabel.textContent = original;
    });
  }

  // ---------- Aparición suave de las secciones ----------
  var reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && reveals.length) {
    doc.classList.add('js-reveal');
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    // red de seguridad: nada queda oculto aunque el navegador no avise del scroll
    var showAll = function () { reveals.forEach(function (el) { el.classList.add('is-in'); }); };
    window.addEventListener('scroll', function onScroll() {
      if (window.scrollY + window.innerHeight >= document.body.scrollHeight - 40) { showAll(); window.removeEventListener('scroll', onScroll); }
    }, { passive: true });
    setTimeout(showAll, 6000);
    reveals.forEach(function (el) {
      // lo que ya está a la vista aparece sin esperar
      if (el.getBoundingClientRect().top < window.innerHeight) el.classList.add('is-in');
      else io.observe(el);
    });
  }

  // ---------- Botón flotante en móvil: se oculta cuando la colecta está a la vista ----------
  var cta = document.querySelector('[data-sticky-cta]');
  var fund = document.getElementById('donar');
  if (cta && fund && 'IntersectionObserver' in window) {
    var fundVisible = false;
    var update = function () { cta.classList.toggle('is-hidden', fundVisible || window.scrollY < 320); };
    new IntersectionObserver(function (entries) {
      fundVisible = entries[0].isIntersecting;
      update();
    }, { threshold: 0.05 }).observe(fund);
    window.addEventListener('scroll', update, { passive: true });
    update();
  }

  // ---------- Actualiza la barra cada minuto sin recargar ----------
  var bar = document.querySelector('[data-bar]');
  if (!bar || !window.fetch) return;
  // la barra se llena con una animación suave al cargar
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var target = bar.style.width;
    bar.style.width = '0';
    void bar.offsetWidth;
    requestAnimationFrame(function () { setTimeout(function () { bar.style.width = target; }, 250); });
  }
  var set = function (sel, text) { var el = document.querySelector(sel); if (el) el.textContent = text; };
  function refresh() {
    fetch('estado.php', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (p) {
        bar.style.width = p.percent + '%';
        bar.parentNode.setAttribute('aria-valuenow', p.percent);
        set('[data-raised]', p.raised_text);
        set('[data-goal]', p.goal_text);
        set('[data-percent]', p.percent + '%');
        set('[data-count]', p.count);
        set('[data-count-label]', p.count === 1 ? 'persona ha aportado' : 'personas han aportado');
        if (p.donated_text) {
          set('[data-donated]', p.donated_text);
          set('[data-fees-total]', p.fees_text);
          set('[data-fee-pct]', p.fee_pct_text);
          set('[data-net]', p.net_text);
          if (p.count > 0) { var g = document.querySelector('[data-fees-grid]'); if (g) g.classList.remove('is-empty'); }
        }
        var last = document.querySelector('[data-last]');
        if (last && p.last_name) {
          var nameEl = last.querySelector('[data-last-name]');
          var changed = nameEl.textContent !== p.last_name || last.classList.contains('is-empty');
          nameEl.textContent = p.last_name;
          set('[data-last-ago]', p.last_ago || '');
          last.classList.remove('is-empty');
          if (changed) { last.classList.remove('is-new'); void last.offsetWidth; last.classList.add('is-new'); }
        }
      })
      .catch(function () {});
  }
  setInterval(function () { if (!document.hidden) refresh(); }, 30000);
})();
