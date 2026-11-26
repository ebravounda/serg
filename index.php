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
<script>document.documentElement.className = 'js';</script>
<link rel="stylesheet" href="assets/css/memorial.css?v=1">
</head>
<body>

<header class="top">
  <p class="eyebrow">En memoria de</p>
</header>

<main>
  <section class="hero">
    <div class="portrait">
      <?php if ($photo): ?>
        <img src="<?= e($photo) ?>" alt="<?= e($s['name']) ?>">
      <?php else: ?>
        <span class="monogram" aria-hidden="true"><?= e(mb_substr($s['name'], 0, 1)) ?></span>
      <?php endif; ?>
    </div>
    <h1><?= e($s['name']) ?></h1>
    <?php if (trim($s['dates']) !== ''): ?><p class="dates"><?= e($s['dates']) ?></p><?php endif; ?>
    <div class="rule" aria-hidden="true"></div>
    <?php if (trim($s['epitaph']) !== ''): ?><p class="epitaph"><?= e($s['epitaph']) ?></p><?php endif; ?>
  </section>

  <section class="tribute">
    <?= paragraphs($s['tribute']) ?>
  </section>

  <section class="fund" id="donar" aria-labelledby="fund-title">
    <h2 id="fund-title">Colecta para el servicio funerario</h2>

    <div class="progress-figures">
      <p><strong data-raised><?= money($p['raised']) ?></strong> recaudados</p>
      <p class="goal">Meta: <span data-goal><?= money($p['goal']) ?></span></p>
    </div>
    <div class="bar" role="progressbar" aria-label="Progreso de la colecta"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $p['percent'] ?>">
      <span data-bar style="width: <?= $p['percent'] ?>%"></span>
    </div>
    <p class="progress-meta">
      <span data-percent><?= $p['percent'] ?>%</span> de la meta ·
      <span data-count><?= $p['count'] ?></span> <span data-count-label><?= $p['count'] === 1 ? 'aporte' : 'aportes' ?></span>
    </p>

    <?php if ($p['raised'] >= $p['goal']): ?>
      <p class="notice">Gracias a todos, hemos alcanzado la meta.</p>
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
        <button type="submit" class="btn">Realiza tu donación</button>
        <p class="secure">Pago seguro con tarjeta a través de Stripe. Recibirás un justificante por correo.</p>
      </form>
    <?php endif; ?>

    <?php if (trim($s['transparency']) !== ''): ?>
      <div class="transparency"><?= paragraphs($s['transparency']) ?></div>
    <?php endif; ?>
  </section>

  <section class="updates" aria-labelledby="updates-title">
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
  <p><?= e($s['organizer']) ?></p>
</footer>

<script src="assets/js/memorial.js?v=1" defer></script>
</body>
</html>
