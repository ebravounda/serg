<?php
require __DIR__ . '/../lib/bootstrap.php';

/* ---------------- Sesión segura ---------------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('memorial_admin');
session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];
$logged = !empty($_SESSION['admin']);
$hash = (string) cfg('admin_password_hash');

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$msg, $type];
}

function go(string $query = ''): never
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
}

function save_photo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se recibió la imagen (máximo ' . ini_get('upload_max_filesize') . ').');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('La foto debe ser JPG, PNG o WEBP.');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $name = 'retrato-' . bin2hex(random_bytes(4)) . '.jpg';
    $dest = UPLOAD_DIR . '/' . $name;

    if (function_exists('imagecreatefromstring')) {
        // Se vuelve a codificar: elimina metadatos y garantiza que es una imagen
        $img = imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$img) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
            $img = match ($o) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img };
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, 900 / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($out, true);
        imagejpeg($out, $dest, 85);
    } else {
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('Este servidor solo admite fotos JPG.');
        }
        move_uploaded_file($file['tmp_name'], $dest);
    }
    return $name;
}

/* ---------------- Acciones ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        flash('La sesión expiró. Vuelve a intentarlo.', 'error');
        go();
    }

    if ($action === 'login') {
        $ip = client_ip();
        $now = time();
        $tries = store_update('login', function ($all) use ($ip, $now) {
            $all[$ip] = array_values(array_filter($all[$ip] ?? [], fn($t) => $t > $now - 900));
            return $all;
        })[$ip];
        if (count($tries) >= 5) {
            flash('Demasiados intentos. Espera 15 minutos.', 'error');
            go();
        }
        if ($hash !== '' && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            store_update('login', function ($all) use ($ip) { unset($all[$ip]); return $all; });
            go();
        }
        store_update('login', function ($all) use ($ip, $now) { $all[$ip][] = $now; return $all; });
        sleep(1);
        flash('Contraseña incorrecta.', 'error');
        go();
    }

    if (!$logged) {
        go();
    }

    try {
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                go();

            case 'update_save':
                $id = (string) ($_POST['id'] ?? '');
                $title = trim((string) ($_POST['title'] ?? ''));
                $body = trim((string) ($_POST['body'] ?? ''));
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_POST['date'] ?? ''), new DateTimeZone('Europe/Madrid'));
                if ($body === '') {
                    throw new RuntimeException('Escribe el texto de la actualización.');
                }
                $date = $d ? $d->setTime(12, 0)->getTimestamp() : time();
                store_update('updates', function ($all) use ($id, $title, $body, $date) {
                    if ($id !== '' && isset($all[$id])) {
                        $all[$id] = ['title' => $title, 'body' => $body, 'date' => $date] + $all[$id];
                    } else {
                        $nid = bin2hex(random_bytes(6));
                        $all[$nid] = ['id' => $nid, 'title' => $title, 'body' => $body, 'date' => $date, 'created' => time()];
                    }
                    return $all;
                });
                flash($id !== '' ? 'Actualización modificada.' : 'Actualización publicada.');
                go('tab=updates');

            case 'update_delete':
                $id = (string) ($_POST['id'] ?? '');
                store_update('updates', function ($all) use ($id) { unset($all[$id]); return $all; });
                flash('Actualización eliminada.');
                go('tab=updates');

            case 'settings_save':
                $goal = (float) str_replace(',', '.', (string) ($_POST['goal'] ?? '0'));
                if ($goal < 1) {
                    throw new RuntimeException('La meta debe ser mayor que 0.');
                }
                $new = [
                    'name' => trim((string) ($_POST['name'] ?? '')) ?: default_settings()['name'],
                    'dates' => trim((string) ($_POST['dates'] ?? '')),
                    'epitaph' => trim((string) ($_POST['epitaph'] ?? '')),
                    'tribute' => trim((string) ($_POST['tribute'] ?? '')),
                    'transparency' => trim((string) ($_POST['transparency'] ?? '')),
                    'organizer' => trim((string) ($_POST['organizer'] ?? '')),
                    'goal_cents' => (int) round($goal * 100),
                    'donations_open' => !empty($_POST['donations_open']),
                ];
                store_update('settings', fn($s) => $new + $s);
                flash('Ajustes guardados.');
                go('tab=settings');

            case 'photo_upload':
                $name = save_photo($_FILES['photo'] ?? []);
                $old = settings()['photo'];
                store_update('settings', fn($s) => ['photo' => $name] + $s);
                if ($old !== '' && is_file(UPLOAD_DIR . '/' . basename($old))) {
                    unlink(UPLOAD_DIR . '/' . basename($old));
                }
                flash('Foto actualizada.');
                go('tab=settings');

            case 'photo_delete':
                $old = settings()['photo'];
                store_update('settings', fn($s) => ['photo' => ''] + $s);
                if ($old !== '' && is_file(UPLOAD_DIR . '/' . basename($old))) {
                    unlink(UPLOAD_DIR . '/' . basename($old));
                }
                flash('Foto eliminada.');
                go('tab=settings');

            case 'sync':
                $n = sync_with_stripe();
                flash("Sincronizado con Stripe: $n pagos de esta colecta encontrados.");
                go('tab=donations');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        go('tab=' . rawurlencode((string) ($_POST['tab'] ?? 'updates')));
    }
    go();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$tab = in_array($_GET['tab'] ?? '', ['updates', 'donations', 'settings'], true) ? $_GET['tab'] : 'updates';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel · Memorial</title>
<link rel="stylesheet" href="../assets/css/memorial.css?v=1">
<link rel="stylesheet" href="../assets/css/admin.css?v=1">
</head>
<body class="admin">
<?php if (!$logged): ?>
  <main class="login">
    <p class="eyebrow">Panel de administración</p>
    <h1>Acceso</h1>
    <?php if ($flash): ?><p class="flash flash-<?= e($flash[1]) ?>"><?= e($flash[0]) ?></p><?php endif; ?>
    <?php if ($hash === ''): ?>
      <p class="flash flash-error">Falta configurar la contraseña: completa <code>admin_password_hash</code> en <code>config.php</code> (instrucciones en el README).</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label>Contraseña <input type="password" name="password" required autofocus autocomplete="current-password"></label>
        <button class="btn">Entrar</button>
      </form>
    <?php endif; ?>
  </main>
<?php else:
  $s = settings();
  $p = progress();
?>
  <header class="admin-head">
    <div>
      <p class="eyebrow">Panel · En memoria de <?= e($s['name']) ?></p>
      <p class="admin-total"><strong><?= money($p['raised']) ?></strong> de <?= money($p['goal']) ?> · <?= $p['percent'] ?>% · <?= $p['count'] ?> aportes</p>
    </div>
    <div class="admin-actions">
      <a href="../" target="_blank" rel="noopener">Ver la web ↗</a>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button name="action" value="logout" class="link">Salir</button></form>
    </div>
  </header>
  <nav class="tabs">
    <a href="?tab=updates" class="<?= $tab === 'updates' ? 'on' : '' ?>">Actualizaciones</a>
    <a href="?tab=donations" class="<?= $tab === 'donations' ? 'on' : '' ?>">Donaciones</a>
    <a href="?tab=settings" class="<?= $tab === 'settings' ? 'on' : '' ?>">Ajustes</a>
  </nav>

  <main class="panel">
    <?php if ($flash): ?><p class="flash flash-<?= e($flash[1]) ?>"><?= e($flash[0]) ?></p><?php endif; ?>
    <?php if (!stripe_ready()): ?>
      <p class="flash flash-error">Stripe no está configurado todavía: el botón de donación no aparecerá hasta completar <code>stripe_secret_key</code> en <code>config.php</code>.</p>
    <?php elseif (stripe_test_mode()): ?>
      <p class="flash flash-warn">Stripe está en <strong>modo de prueba</strong>: los pagos no son reales.</p>
    <?php endif; ?>

    <?php if ($tab === 'updates'):
      $all = updates();
      $editing = null;
      foreach ($all as $u) {
          if ($u['id'] === ($_GET['edit'] ?? '')) { $editing = $u; }
      }
    ?>
      <section class="box">
        <h2><?= $editing ? 'Editar actualización' : 'Nueva actualización' ?></h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="update_save">
          <input type="hidden" name="tab" value="updates">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
          <div class="row">
            <label class="grow">Título <span class="hint">(opcional)</span>
              <input name="title" maxlength="140" value="<?= e($editing['title'] ?? '') ?>" placeholder="Ej. Hemos cubierto el servicio de la funeraria">
            </label>
            <label>Fecha
              <input type="date" name="date" value="<?= e(date('Y-m-d', (int) ($editing['date'] ?? time()))) ?>" required>
            </label>
          </div>
          <label>Texto
            <textarea name="body" rows="6" required placeholder="Cuenta cómo avanza el proceso…"><?= e($editing['body'] ?? '') ?></textarea>
          </label>
          <p class="hint">Deja una línea en blanco para separar párrafos.</p>
          <button class="btn btn-small"><?= $editing ? 'Guardar cambios' : 'Publicar' ?></button>
          <?php if ($editing): ?><a href="?tab=updates" class="cancel">Cancelar</a><?php endif; ?>
        </form>
      </section>

      <section class="box">
        <h2>Publicadas (<?= count($all) ?>)</h2>
        <?php if (!$all): ?><p class="hint">Todavía no hay actualizaciones.</p><?php endif; ?>
        <?php foreach ($all as $u): ?>
          <article class="item">
            <div>
              <p class="item-date"><?= e(date_es((int) $u['date'])) ?></p>
              <?php if ($u['title'] !== ''): ?><h3><?= e($u['title']) ?></h3><?php endif; ?>
              <p class="item-body"><?= nl2br(e(mb_strimwidth($u['body'], 0, 280, '…'))) ?></p>
            </div>
            <div class="item-actions">
              <a href="?tab=updates&amp;edit=<?= e($u['id']) ?>">Editar</a>
              <form method="post" onsubmit="return confirm('¿Eliminar esta actualización?')">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                <button name="action" value="update_delete" class="link danger">Eliminar</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

    <?php elseif ($tab === 'donations'):
      $list = donations();
      $state = store_read('state', []);
      $dash = 'https://dashboard.stripe.com/' . (stripe_test_mode() ? 'test/' : '') . 'payments/';
    ?>
      <section class="box">
        <div class="box-head">
          <h2>Donaciones recibidas por la web</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="tab" value="donations">
            <button name="action" value="sync" class="btn btn-small btn-outline">Sincronizar con Stripe</button>
          </form>
        </div>
        <p class="hint">Solo se cuentan los pagos hechos con el botón de esta web (etiqueta <code>campaign = <?= e((string) cfg('campaign_id')) ?></code>). Los reembolsos se descuentan automáticamente.
          <?php if (!empty($state['last_sync'])): ?>Última sincronización: <?= e(date_es((int) $state['last_sync'], true)) ?>.<?php endif; ?></p>
        <?php if (!$list): ?>
          <p class="hint">Aún no hay donaciones.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Fecha</th><th>Nombre</th><th>Email</th><th class="num">Importe</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($list as $d): ?>
                <tr class="<?= $d['amount'] <= 0 ? 'refunded' : '' ?>">
                  <td><?= e(date_es((int) $d['created'], true)) ?></td>
                  <td><?= e($d['name'] ?: '—') ?></td>
                  <td><?= e($d['email'] ?: '—') ?></td>
                  <td class="num"><?= $d['amount'] > 0 ? money((int) $d['amount']) : 'Reembolsado' ?></td>
                  <td><a href="<?= e($dash . rawurlencode($d['id'])) ?>" target="_blank" rel="noopener">Stripe ↗</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td colspan="3">Total</td><td class="num"><?= money($p['raised']) ?></td><td></td></tr></tfoot>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php else: $photo = photo_url($s); ?>
      <section class="box">
        <h2>Foto</h2>
        <div class="photo-row">
          <div class="portrait portrait-small">
            <?php if ($photo): ?><img src="../<?= e($photo) ?>" alt=""><?php else: ?><span class="monogram"><?= e(mb_substr($s['name'], 0, 1)) ?></span><?php endif; ?>
          </div>
          <div>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="tab" value="settings">
              <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
              <button name="action" value="photo_upload" class="btn btn-small">Subir foto</button>
            </form>
            <?php if ($s['photo'] !== ''): ?>
              <form method="post" onsubmit="return confirm('¿Quitar la foto subida y volver a la original?')">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button name="action" value="photo_delete" class="link danger">Volver a la foto original</button>
              </form>
            <?php endif; ?>
            <p class="hint">Se mostrará en un círculo; mejor una foto cuadrada con la cara centrada. Si no subes ninguna, se usa la foto incluida en la web.</p>
          </div>
        </div>
      </section>

      <section class="box">
        <h2>Textos y meta</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="settings_save">
          <input type="hidden" name="tab" value="settings">
          <div class="row">
            <label class="grow">Nombre <input name="name" value="<?= e($s['name']) ?>" required></label>
            <label class="grow">Fechas <span class="hint">(ej. 1985 – 2026)</span> <input name="dates" value="<?= e($s['dates']) ?>"></label>
          </div>
          <label>Frase bajo el nombre <input name="epitaph" value="<?= e($s['epitaph']) ?>"></label>
          <label>Texto de homenaje <textarea name="tribute" rows="7"><?= e($s['tribute']) ?></textarea></label>
          <label>Nota sobre el destino del dinero <textarea name="transparency" rows="3"><?= e($s['transparency']) ?></textarea></label>
          <label>Pie de página <input name="organizer" value="<?= e($s['organizer']) ?>"></label>
          <div class="row">
            <label>Meta (€) <input type="number" name="goal" min="1" step="1" value="<?= e((string) round($s['goal_cents'] / 100)) ?>" required></label>
            <label class="check"><input type="checkbox" name="donations_open" value="1" <?= $s['donations_open'] ? 'checked' : '' ?>> Donaciones abiertas</label>
          </div>
          <button class="btn btn-small">Guardar ajustes</button>
        </form>
      </section>
    <?php endif; ?>
  </main>
<?php endif; ?>
</body>
</html>
