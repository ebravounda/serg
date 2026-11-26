<?php
require __DIR__ . '/lib/bootstrap.php';

$confirmed = false;
$sid = (string) ($_GET['session_id'] ?? '');
if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sid) && stripe_ready()) {
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
<link rel="stylesheet" href="assets/css/memorial.css?v=1">
</head>
<body>
<main class="thanks">
  <p class="eyebrow">En memoria de <?= e($s['name']) ?></p>
  <h1>Gracias</h1>
  <div class="rule" aria-hidden="true"></div>
  <?php if ($confirmed): ?>
    <p>Tu donación se ha recibido correctamente. Tu gesto significa mucho para todos los que queríamos a Sergio y para su familia.</p>
  <?php else: ?>
    <p>Gracias por tu gesto. Estamos confirmando el pago; en unos minutos se verá reflejado en la colecta.</p>
  <?php endif; ?>
  <p class="muted">Llevamos <?= money($p['raised']) ?> de <?= money($p['goal']) ?> (<?= $p['percent'] ?>%).</p>
  <p><a class="btn btn-outline" href="index.php">Volver</a></p>
</main>
</body>
</html>
