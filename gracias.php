<?php
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/mail.php';

$confirmed = false;
$sid = (string) ($_GET['session_id'] ?? '');
if (preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $sid) && stripe_ready() && rate_limit('gracias', 30, 600)) {
    try {
        $confirmed = record_checkout_session($sid);
    } catch (Throwable $ex) {
        error_log('memorial gracias: ' . $ex->getMessage());
    }
}
$s = settings();
$p = progress();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gracias · En memoria de <?= e($s['name']) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/css/memorial.css?v=4">
</head>
<body>
<main class="thanks">
  <svg class="candle" viewBox="0 0 40 72" aria-hidden="true" focusable="false">
    <defs><radialGradient id="glow" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#ffd99a" stop-opacity=".9"/><stop offset="1" stop-color="#ffd99a" stop-opacity="0"/></radialGradient></defs>
    <circle class="candle-glow" cx="20" cy="18" r="18" fill="url(#glow)"/>
    <path class="candle-flame" d="M20 6c4 6 6 9 6 13a6 6 0 0 1-12 0c0-4 2-7 6-13z" fill="#f2b45a"/>
    <path d="M20 13c2 3 3 5 3 7a3 3 0 0 1-6 0c0-2 1-4 3-7z" fill="#fff1c9"/>
    <rect x="19.3" y="25" width="1.4" height="5" rx=".7" fill="#4a3a30"/>
    <rect x="12" y="30" width="16" height="38" rx="2" fill="#efe4d3" stroke="#d9c9b2"/>
  </svg>
  <p class="eyebrow">En memoria de <?= e($s['name']) ?></p>
  <h1>Gracias</h1>
  <div class="divider" aria-hidden="true"><span></span><svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-9.6-9.1C.9 8.6 3 5 6.5 5c2.1 0 3.6 1.2 4.5 2.6l1 1.5 1-1.5C13.9 6.2 15.4 5 17.5 5 21 5 23.1 8.6 21.6 11.9 19.5 16.4 12 21 12 21z"/></svg><span></span></div>
  <?php if ($confirmed): ?>
    <p>Tu donación se ha recibido correctamente. Tu gesto significa muchísimo para todos los que queríamos a Sergio y, sobre todo, para su familia.</p>
    <?php if (mail_ready() && mail_templates()['thanks_enabled']): ?><p class="muted">Te hemos enviado un correo con nuestro agradecimiento.</p><?php endif; ?>
  <?php else: ?>
    <p>Gracias por tu gesto. Estamos confirmando el pago; en unos minutos se verá reflejado en la colecta.</p>
  <?php endif; ?>
  <p class="muted">Llevamos <?= money($p['raised']) ?> de <?= money($p['goal']) ?> (<?= $p['percent'] ?>%).</p>
  <p><a class="btn btn-outline" href="index.php">Volver</a></p>
</main>
</body>
</html>
