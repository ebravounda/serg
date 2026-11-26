<?php
require __DIR__ . '/lib/bootstrap.php';

maybe_auto_sync();
$s = settings();
$p = progress();
$photo = photo_url($s);
$updates = updates();
$error = $_GET['error'] ?? '';
$min = (int) cfg('min_amount');
$max = (int) cfg('max_amount');
$presets = [10, 20, 50, 100];
$last = last_donation();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>En memoria de <?= e($s['name']) ?></title>
<meta name="description" content="<?= e('En memoria de ' . $s['name'] . '. Colecta entre amigos para cubrir los gastos del servicio funerario.') ?>">
<meta property="og:title" content="<?= e('En memoria de ' . $s['name']) ?>">
<meta property="og:description" content="Colecta entre amigos para cubrir los gastos del servicio funerario.">
<?php if ($photo): ?><meta property="og:image" content="<?= e(site_url($photo)) ?>"><?php endif; ?>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#f8f3ec">
<noscript><link rel="stylesheet" href="assets/css/nojs.css"></noscript>
<link rel="stylesheet" href="assets/css/memorial.css?v=5">
</head>
<body>

<main>
  <section class="hero">
    <svg class="candle" viewBox="0 0 40 72" aria-hidden="true" focusable="false">
      <defs><radialGradient id="glow" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#ffd99a" stop-opacity=".9"/><stop offset="1" stop-color="#ffd99a" stop-opacity="0"/></radialGradient></defs>
      <circle class="candle-glow" cx="20" cy="18" r="18" fill="url(#glow)"/>
      <path class="candle-flame" d="M20 6c4 6 6 9 6 13a6 6 0 0 1-12 0c0-4 2-7 6-13z" fill="#f2b45a"/>
      <path d="M20 13c2 3 3 5 3 7a3 3 0 0 1-6 0c0-2 1-4 3-7z" fill="#fff1c9"/>
      <rect x="19.3" y="25" width="1.4" height="5" rx=".7" fill="#4a3a30"/>
      <rect x="12" y="30" width="16" height="38" rx="2" fill="#efe4d3" stroke="#d9c9b2"/>
    </svg>
    <p class="eyebrow">En memoria de</p>
    <div class="portrait">
      <?php if ($photo): ?>
        <img src="<?= e($photo) ?>" alt="<?= e($s['name']) ?>" width="600" height="600">
      <?php else: ?>
        <span class="monogram" aria-hidden="true"><?= e(mb_substr($s['name'], 0, 1)) ?></span>
      <?php endif; ?>
    </div>
    <h1><?= e($s['name']) ?></h1>
    <?php if (trim($s['dates']) !== ''): ?><p class="dates"><?= e($s['dates']) ?></p><?php endif; ?>
    <div class="divider" aria-hidden="true"><span></span><svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-9.6-9.1C.9 8.6 3 5 6.5 5c2.1 0 3.6 1.2 4.5 2.6l1 1.5 1-1.5C13.9 6.2 15.4 5 17.5 5 21 5 23.1 8.6 21.6 11.9 19.5 16.4 12 21 12 21z"/></svg><span></span></div>
    <?php if (trim($s['epitaph']) !== ''): ?><p class="epitaph"><?= e($s['epitaph']) ?></p><?php endif; ?>
  </section>

  <section class="tribute reveal">
    <?= paragraphs($s['tribute']) ?>
  </section>

  <section class="fund reveal" id="donar" aria-labelledby="fund-title">
    <p class="fund-kicker">Entre todos</p>
    <h2 id="fund-title">Despidamos a Sergio como se merece</h2>
    <p class="fund-lead">Cada aporte, grande o pequeño, nos ayuda a cubrir los gastos del servicio funerario.</p>

    <div class="progress-figures">
      <p class="raised"><strong data-raised><?= money($p['raised']) ?></strong> <span>recaudados</span></p>
      <p class="goal">Meta: <span data-goal><?= money($p['goal']) ?></span></p>
    </div>
    <div class="bar" role="progressbar" aria-label="Progreso de la colecta"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $p['percent'] ?>">
      <span data-bar style="width: <?= $p['percent'] ?>%"></span>
    </div>
    <p class="progress-meta">
      <span data-percent><?= $p['percent'] ?>%</span> de la meta ·
      <span data-count><?= $p['count'] ?></span> <span data-count-label><?= $p['count'] === 1 ? 'persona ha aportado' : 'personas han aportado' ?></span> con cariño
    </p>

    <p class="last-donation<?= $last ? '' : ' is-empty' ?>" data-last aria-live="polite">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.1C.9 8.6 3 5 6.5 5c2.1 0 3.6 1.2 4.5 2.6l1 1.5 1-1.5C13.9 6.2 15.4 5 17.5 5 21 5 23.1 8.6 21.6 11.9 19.5 16.4 12 21 12 21z"/></svg>
      <span>Última donación: <strong data-last-name><?= e($last['name'] ?? '') ?></strong> <small data-last-ago><?= e($last['ago'] ?? '') ?></small></span>
    </p>

    <?php if ($p['raised'] >= $p['goal']): ?>
      <p class="notice notice-goal">Gracias de corazón: entre todos hemos alcanzado la meta.</p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <p class="notice notice-error" role="alert"><?= e($error) ?></p>
    <?php elseif (isset($_GET['cancelado'])): ?>
      <p class="notice">La donación no se completó. Puedes intentarlo de nuevo cuando quieras.</p>
    <?php endif; ?>

    <?php if (!$s['donations_open']): ?>
      <p class="notice">La colecta está cerrada. Gracias de corazón a todos los que habéis colaborado.</p>
    <?php elseif (!stripe_ready()): ?>
      <p class="notice">Las donaciones estarán disponibles en breve.</p>
    <?php else: ?>
      <form class="donate" action="donar.php" method="post">
        <fieldset>
          <legend>Elige un importe</legend>
          <div class="amounts">
            <?php foreach ($presets as $i => $a): ?>
              <label class="amount">
                <input type="radio" name="amount" value="<?= $a ?>" <?= $i === 1 ? 'checked' : '' ?>>
                <span><?= $a ?> €</span>
              </label>
            <?php endforeach; ?>
            <label class="amount amount-other">
              <input type="radio" name="amount" value="otro">
              <span>Otro</span>
            </label>
          </div>
          <div class="custom" data-custom>
            <label for="custom">Importe (€)</label>
            <input id="custom" name="custom" type="number" inputmode="decimal"
                   min="<?= $min ?>" max="<?= $max ?>" step="1" placeholder="Ej. 30">
          </div>
        </fieldset>
        <div class="donor">
          <label for="donor_name">Tu nombre y apellido</label>
          <input id="donor_name" name="donor_name" type="text" required minlength="3" maxlength="60"
                 autocomplete="name" autocapitalize="words" placeholder="Ej. Juan Pérez"
                 pattern="\s*[^\s]+\s+[^\s].*" title="Escribe tu nombre y apellido">
          <label class="anon"><input type="checkbox" name="anonymous" value="1"> Prefiero que mi nombre no aparezca en la web</label>
        </div>
        <div class="hp" aria-hidden="true"><label>No rellenar <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <button type="submit" class="btn btn-donate"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.1C.9 8.6 3 5 6.5 5c2.1 0 3.6 1.2 4.5 2.6l1 1.5 1-1.5C13.9 6.2 15.4 5 17.5 5 21 5 23.1 8.6 21.6 11.9 19.5 16.4 12 21 12 21z"/></svg><span>Realiza tu donación</span></button>
        <p class="secure"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V7a5 5 0 0 1 10 0v3h1a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h1zm2 0h6V7a3 3 0 0 0-6 0v3z"/></svg> Pago seguro con tarjeta, Apple Pay o Google Pay a través de Stripe. Al pagar puedes dejar un mensaje para la familia.</p>
      </form>
    <?php endif; ?>

    <?php if (trim($s['transparency']) !== ''): ?>
      <div class="transparency"><?= paragraphs($s['transparency']) ?></div>
    <?php endif; ?>
  </section>

  <?php $messages = public_messages(); if ($messages): ?>
  <section class="messages reveal" aria-labelledby="messages-title">
    <h2 id="messages-title">Mensajes de cariño</h2>
    <div class="messages-grid">
    <?php foreach ($messages as $m): ?>
      <blockquote>
        <p><?= nl2br(e($m['message'])) ?></p>
        <cite>— <?= e(public_donor_name($m)) ?></cite>
      </blockquote>
    <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="updates reveal" aria-labelledby="updates-title">
    <h2 id="updates-title">Actualizaciones</h2>
    <?php if (!$updates): ?>
      <p class="muted">Aquí iremos compartiendo cómo avanza todo el proceso.</p>
    <?php else: ?>
      <ol class="timeline">
        <?php foreach ($updates as $u): ?>
          <li>
            <time datetime="<?= e(date('Y-m-d', (int) $u['date'])) ?>"><?= e(date_es((int) $u['date'])) ?></time>
            <?php if (($u['title'] ?? '') !== ''): ?><h3><?= e($u['title']) ?></h3><?php endif; ?>
            <?= paragraphs($u['body']) ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</main>

<footer class="foot">
  <p class="foot-thanks">Gracias por encender una luz por <?= e(explode(' ', $s['name'])[0]) ?>.</p>
  <p><?= e($s['organizer']) ?></p>
</footer>

<?php if ($s['donations_open'] && stripe_ready()): ?>
<a class="sticky-cta" href="#donar" data-sticky-cta>
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.1C.9 8.6 3 5 6.5 5c2.1 0 3.6 1.2 4.5 2.6l1 1.5 1-1.5C13.9 6.2 15.4 5 17.5 5 21 5 23.1 8.6 21.6 11.9 19.5 16.4 12 21 12 21z"/></svg>
  Realiza tu donación
</a>
<?php endif; ?>

<script src="assets/js/memorial.js?v=4" defer></script>
</body>
</html>
