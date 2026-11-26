<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/mail.php';

require __DIR__ . '/../lib/stats.php';

/* ---------------- Acceso restringido por IP (opcional, config.php) ---------------- */
$allowedIps = (array) cfg('admin_allowed_ips', []);
if ($allowedIps && !in_array(client_ip(), $allowedIps, true)) {
    http_response_code(403);
    exit('Acceso no permitido.');
}

/* ---------------- Sesión segura ---------------- */
session_name(is_https() ? '__Host-memorial_admin' : 'memorial_admin');
session_set_cookie_params(['httponly' => true, 'secure' => is_https(), 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Caducidad: 2 h sin actividad o 12 h desde que se entró; ligada al navegador
$fingerprint = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if (!empty($_SESSION['admin'])) {
    $idle = time() - (int) ($_SESSION['last_seen'] ?? 0);
    $age = time() - (int) ($_SESSION['login_at'] ?? 0);
    if ($idle > 7200 || $age > 43200 || ($_SESSION['fp'] ?? '') !== $fingerprint) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = ['La sesión caducó. Vuelve a entrar.', 'warn'];
    } else {
        $_SESSION['last_seen'] = time();
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];
$logged = !empty($_SESSION['admin']);
$hash = admin_password_hash();

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$msg, $type];
}

function go(string $query = ''): never
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function save_photo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se recibió la imagen (máximo ' . ini_get('upload_max_filesize') . ').');
    }
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] > 12 * 1024 * 1024) {
        throw new RuntimeException('La foto no puede pesar más de 12 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('La foto debe ser JPG, PNG o WEBP.');
    }
    if ($info[0] * $info[1] > 50_000_000) {
        throw new RuntimeException('La foto tiene demasiada resolución (máx. 50 megapíxeles).');
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
        $ip = hash('sha256', client_ip());
        $now = time();
        $state = store_update('login', function ($all) use ($ip, $now) {
            foreach ($all as $k => $times) {
                $all[$k] = array_values(array_filter((array) $times, fn($t) => $t > $now - 900));
                if (!$all[$k]) {
                    unset($all[$k]);
                }
            }
            return $all;
        });
        // máx. 5 fallos por IP y 60 en total cada 15 minutos (frena ataques de fuerza bruta)
        if (count($state[$ip] ?? []) >= 5 || count($state['_global'] ?? []) >= 60) {
            flash('Demasiados intentos fallidos. Espera 15 minutos.', 'error');
            go();
        }
        if ($hash !== '' && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['login_at'] = $_SESSION['last_seen'] = time();
            $_SESSION['fp'] = $fingerprint;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            store_update('login', function ($all) use ($ip) { unset($all[$ip]); return $all; });
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                store_update('admin', fn($a) => ['password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT)] + $a);
            }
            go();
        }
        store_update('login', function ($all) use ($ip, $now) { $all[$ip][] = $now; $all['_global'][] = $now; return $all; });
        error_log('memorial: intento de acceso fallido al panel desde ' . client_ip());
        usleep(random_int(600000, 1200000));
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
                    'bar_mode' => ($_POST['bar_mode'] ?? 'net') === 'gross' ? 'gross' : 'net',
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

            case 'integrations_save':
                $in = store_read('integrations', []);
                $secrets = [
                    'stripe_secret_key' => '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/',
                    'stripe_webhook_secret' => '/^whsec_[A-Za-z0-9+\/=]+$/',
                    'resend_api_key' => '/^re_[A-Za-z0-9_]+$/',
                ];
                foreach ($secrets as $k => $re) {
                    $v = trim((string) ($_POST[$k] ?? ''));
                    if (!empty($_POST['clear_' . $k])) {
                        $in[$k] = '';
                    } elseif ($v !== '') {
                        if (!preg_match($re, $v)) {
                            throw new RuntimeException('El formato de «' . $k . '» no es válido. Revisa que lo hayas copiado completo.');
                        }
                        $in[$k] = $v;
                    }
                }
                $pid = trim((string) ($_POST['stripe_product_id'] ?? ''));
                if ($pid !== '' && !preg_match('/^prod_[A-Za-z0-9]+$/', $pid)) {
                    throw new RuntimeException('El ID de producto debe empezar por prod_.');
                }
                $in['stripe_product_id'] = $pid;
                foreach (['mail_from_email' => 'remitente', 'mail_reply_to' => 'responder a', 'notify_email' => 'avisos'] as $k => $label) {
                    $v = trim((string) ($_POST[$k] ?? ''));
                    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException("El correo de «{$label}» no es válido.");
                    }
                    $in[$k] = $v;
                }
                $in['mail_from_name'] = trim((string) ($_POST['mail_from_name'] ?? ''));
                $url = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
                if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~', $url))) {
                    throw new RuntimeException('La dirección de la web no es válida.');
                }
                $in['site_url'] = $url;
                store_update('integrations', fn() => $in);
                flash('Conexiones guardadas.');
                go('tab=connections');

            case 'stripe_test':
                $b = stripe_request('GET', 'balance');
                $mode = !empty($b['livemode']) ? 'modo real' : 'modo de prueba';
                flash("Conexión con Stripe correcta ($mode).");
                go('tab=connections');

            case 'mail_test':
                $to = trim((string) ($_POST['to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Indica un correo válido para la prueba.');
                }
                send_mail('prueba', $to, 'Correo de prueba', "Hola,\n\nEste es un correo de prueba de la web en memoria de " . settings()['name'] . ". Si lo recibes, el envío de correos funciona correctamente.\n\n" . site_url(''));
                flash("Correo de prueba enviado a $to.");
                go('tab=' . rawurlencode((string) ($_POST['tab'] ?? 'connections')));

            case 'templates_save':
                $subject = trim((string) ($_POST['thanks_subject'] ?? ''));
                $body = trim((string) ($_POST['thanks_body'] ?? ''));
                if ($subject === '' || $body === '') {
                    throw new RuntimeException('El asunto y el texto del agradecimiento no pueden estar vacíos.');
                }
                store_update('mail_templates', fn($t) => [
                    'thanks_enabled' => !empty($_POST['thanks_enabled']),
                    'thanks_subject' => $subject,
                    'thanks_body' => $body,
                ] + $t);
                flash('Correo de agradecimiento guardado.');
                go('tab=mail');

            case 'thanks_sample':
                $to = trim((string) ($_POST['to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Indica un correo válido para la prueba.');
                }
                $t = mail_templates();
                $sample = ['name' => 'Nombre Apellido', 'amount' => 2000, 'created' => time()];
                send_mail('prueba', $to, '[Prueba] ' . fill_placeholders($t['thanks_subject'], $sample), fill_placeholders($t['thanks_body'], $sample));
                flash("Ejemplo del agradecimiento enviado a $to.");
                go('tab=mail');

            case 'broadcast':
                $subject = trim((string) ($_POST['subject'] ?? ''));
                $body = trim((string) ($_POST['body'] ?? ''));
                if ($subject === '' || $body === '') {
                    throw new RuntimeException('Escribe el asunto y el mensaje.');
                }
                $_SESSION['draft'] = ['subject' => $subject, 'body' => $body];
                $to = trim((string) ($_POST['to'] ?? ''));
                if (($_POST['mode'] ?? '') === 'test') {
                    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Indica un correo válido para la prueba.');
                    }
                    $sample = ['name' => 'Nombre Apellido', 'amount' => 2000, 'created' => time()];
                    send_mail('prueba', $to, '[Prueba] ' . fill_placeholders($subject, $sample), fill_placeholders($body, $sample));
                    flash("Prueba enviada a $to. Si está bien, envíalo a los donantes.");
                    go('tab=mail');
                }
                if (empty($_POST['confirm'])) {
                    throw new RuntimeException('Marca la casilla de confirmación para enviar a todos los donantes.');
                }
                set_time_limit(0);
                [$ok, $ko] = broadcast($subject, $body);
                unset($_SESSION['draft']);
                flash("Mensaje enviado a $ok donantes." . ($ko ? " Fallaron $ko (ver registro)." : ''), $ko ? 'warn' : 'ok');
                go('tab=mail');

            case 'thanks_resend':
                send_thanks((string) ($_POST['id'] ?? ''), true);
                flash('Agradecimiento enviado.');
                go('tab=donations');

            case 'message_publish':
            case 'message_hide':
            case 'message_delete':
                $id = (string) ($_POST['id'] ?? '');
                store_update('donations', function ($all) use ($id, $action) {
                    if (isset($all[$id])) {
                        if ($action === 'message_delete') {
                            $all[$id]['message'] = '';
                            $all[$id]['message_public'] = false;
                        } else {
                            $all[$id]['message_public'] = $action === 'message_publish';
                        }
                    }
                    return $all;
                });
                flash(['message_publish' => 'Mensaje publicado en la web.', 'message_hide' => 'Mensaje ocultado.', 'message_delete' => 'Mensaje eliminado.'][$action]);
                go('tab=messages');

            case 'password_change':
                $cur = (string) ($_POST['current'] ?? '');
                $new = (string) ($_POST['new'] ?? '');
                if (!password_verify($cur, $hash)) {
                    throw new RuntimeException('La contraseña actual no es correcta.');
                }
                if (mb_strlen($new) < 10) {
                    throw new RuntimeException('La nueva contraseña debe tener al menos 10 caracteres.');
                }
                if ($new !== (string) ($_POST['new2'] ?? '')) {
                    throw new RuntimeException('Las contraseñas nuevas no coinciden.');
                }
                store_update('admin', fn($a) => ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'changed_at' => time()] + $a);
                session_regenerate_id(true);
                flash('Contraseña cambiada.');
                go('tab=connections');

            case 'sync':
                $n = sync_with_stripe();
                flash("Sincronizado con Stripe: $n pagos de esta colecta encontrados.");
                go('tab=' . (($_POST['tab'] ?? '') === 'stats' ? 'stats' : 'donations'));
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
        go('tab=' . rawurlencode((string) ($_POST['tab'] ?? 'stats')));
    }
    go();
}

if ($logged && isset($_GET['export'])) {
    try {
        sync_with_stripe(); // datos frescos antes de exportar
    } catch (Throwable $ex) {
        error_log('memorial export sync: ' . $ex->getMessage());
    }
    $stamp = date('Y-m-d');
    if ($_GET['export'] === 'pdf') {
        require __DIR__ . '/../lib/pdf.php';
        $pdf = build_report_pdf(!empty($_GET['names']));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="informe-colecta-' . $stamp . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="donaciones-' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // para que Excel lea bien los acentos
        $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; // evita inyección de fórmulas
        fputcsv($out, ['Fecha', 'Hora', 'País', 'País de la tarjeta', 'Método', 'Tarjeta', 'Nombre', 'Email', 'Donado (€)', 'Reembolsado (€)', 'Comisión Stripe (€)', 'Neto (€)', 'Mensaje', 'ID de Stripe'], ';');
        foreach (array_reverse(donations()) as $d) {
            $t = local_time((int) $d['created']);
            $eur = fn($c) => number_format(((int) $c) / 100, 2, ',', '');
            fputcsv($out, array_map($cell, [
                $t->format('d/m/Y'), $t->format('H:i:s'), country_name(donation_country($d)), country_name((string) ($d['card_country'] ?? '')),
                method_label($d), ($d['last4'] ?? '') !== '' ? '····' . $d['last4'] : '', $d['name'] ?? '', $d['email'] ?? '',
                $eur($d['gross'] ?? $d['amount']), $eur($d['refunded'] ?? 0), isset($d['fee']) ? $eur($d['fee']) : '', $eur(donation_net($d)),
                $d['message'] ?? '', $d['id'],
            ]), ';');
        }
        fclose($out);
        exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$tabs = ['stats' => 'Estadísticas', 'updates' => 'Actualizaciones', 'donations' => 'Donaciones', 'messages' => 'Mensajes', 'mail' => 'Correos', 'settings' => 'Ajustes', 'connections' => 'Conexiones'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'stats';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel · Memorial</title>
<link rel="stylesheet" href="../assets/css/memorial.css?v=4">
<link rel="stylesheet" href="../assets/css/admin.css?v=4">
<script src="../assets/js/admin.js?v=1" defer></script>
</head>
<body class="admin">
<?php if (!$logged): ?>
  <main class="login">
    <p class="eyebrow">Panel de administración</p>
    <h1>Acceso</h1>
    <?php if ($flash): ?><p class="flash flash-<?= e($flash[1]) ?>"><?= e($flash[0]) ?></p><?php endif; ?>
    <?php if ($hash === ''): ?>
      <p class="flash flash-error">El panel aún no tiene contraseña. Completa la instalación abriendo <code>install.php</code>.</p>
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
    <?php foreach ($tabs as $k => $label): ?>
      <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <main class="panel">
    <?php if ($flash): ?><p class="flash flash-<?= e($flash[1]) ?>"><?= e($flash[0]) ?></p><?php endif; ?>
    <?php if (!stripe_ready()): ?>
      <p class="flash flash-error">Stripe no está configurado todavía: el botón de donación no aparecerá hasta poner la clave secreta en <a href="?tab=connections">Conexiones</a>.</p>
    <?php elseif (stripe_test_mode()): ?>
      <p class="flash flash-warn">Stripe está en <strong>modo de prueba</strong>: los pagos no son reales.</p>
    <?php endif; ?>
    <?php if (!mail_ready() && $tab !== 'connections'): ?>
      <p class="flash flash-warn">El correo no está configurado: no se enviarán agradecimientos hasta completar Resend en <a href="?tab=connections">Conexiones</a>.</p>
    <?php endif; ?>

    <?php if ($tab === 'stats'):
      $st = compute_stats();
      $bal = stripe_balance();
      $list = donations();
      $state = store_read('state', []);
      $dash = 'https://dashboard.stripe.com/' . (stripe_test_mode() ? 'test/' : '') . 'payments/';
      $maxDay = max([1] + array_column($st['by_day'], 'net'));
      $maxHour = max([1] + $st['by_hour']);
    ?>
      <section class="box">
        <div class="box-head">
          <h2>Resumen real de la colecta</h2>
          <div class="head-actions">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="tab" value="stats">
              <button name="action" value="sync" class="btn btn-small btn-outline">Actualizar desde Stripe</button>
            </form>
          </div>
        </div>
        <p class="hint">Solo pagos hechos con el botón de la web<?= stripe_test_mode() ? ' (modo de prueba)' : '' ?>.
          <?php if (!empty($state['last_sync'])): ?>Última actualización: <?= e(date_es((int) $state['last_sync'], true)) ?>.<?php endif; ?></p>

        <div class="kpis">
          <div class="kpi kpi-main">
            <span>Neto que llega a la cuenta</span>
            <strong><?= money($st['net']) ?></strong>
            <em><?= pct(min(100, $st['goal_pct_net'])) ?> de la meta de <?= money($st['goal']) ?> · faltan <?= money($st['remaining']) ?></em>
          </div>
          <div class="kpi"><span>Donado (bruto)</span><strong><?= money($st['gross']) ?></strong><em><?= $st['count'] ?> donaciones · <?= $st['donors'] ?> personas</em></div>
          <div class="kpi"><span>Comisiones de Stripe</span><strong>− <?= money($st['fees']) ?></strong><em><?= pct($st['fee_pct']) ?> de lo donado</em></div>
          <div class="kpi"><span>Reembolsos</span><strong><?= $st['refunded'] ? '− ' . money($st['refunded']) : money(0) ?></strong><em><?= $st['refunded_count'] ?> operaciones</em></div>
          <div class="kpi"><span>Donación media</span><strong><?= money($st['avg']) ?></strong><em>mediana <?= money($st['median']) ?></em></div>
          <div class="kpi"><span>Mayor / menor</span><strong><?= money($st['max']) ?></strong><em>menor <?= money($st['min']) ?></em></div>
        </div>
        <div class="bar big"><span style="width: <?= min(100, round($st['goal_pct_net'], 1)) ?>%"></span></div>
        <?php if ($st['fee_pending']): ?>
          <p class="hint"><?= $st['fee_pending'] ?> donación(es) muy recientes aún sin comisión confirmada por Stripe: se cuentan por su importe bruto hasta la próxima actualización.</p>
        <?php endif; ?>
      </section>

      <section class="box">
        <h2>Comisiones de Stripe y dinero real</h2>
        <div class="table-wrap">
          <table>
            <tbody>
              <tr><td>Total donado</td><td class="num"><?= money($st['gross']) ?></td></tr>
              <?php foreach ($st['fee_types'] as $type => $amt): ?>
                <tr><td>− <?= e(fee_type_label($type)) ?></td><td class="num">− <?= money($amt) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$st['fee_types'] && $st['fees']): ?><tr><td>− Comisiones</td><td class="num">− <?= money($st['fees']) ?></td></tr><?php endif; ?>
              <tr><td>− Reembolsos</td><td class="num">− <?= money($st['refunded']) ?></td></tr>
              <tr class="total"><td>Neto para la colecta</td><td class="num"><?= money($st['net']) ?></td></tr>
              <tr><td class="hint">· ya disponible para transferir al banco</td><td class="num hint"><?= money($st['net_available']) ?></td></tr>
              <tr><td class="hint">· en camino (Stripe lo libera en unos días)</td><td class="num hint"><?= money($st['net_pending']) ?></td></tr>
            </tbody>
          </table>
        </div>
        <?php if ($bal): ?>
          <p class="hint">Saldo actual de la cuenta de Stripe: <strong><?= money($bal['available']) ?></strong> disponible y <strong><?= money($bal['pending']) ?></strong> pendiente.
            Si la cuenta de Stripe es exclusiva de la colecta, este saldo (más lo ya transferido al banco) debe coincidir con el neto.</p>
        <?php endif; ?>
        <p class="hint">La comisión de Stripe en Europa suele ser 1,5 % + 0,25 € por tarjeta europea (más para tarjetas de fuera del EEE). Al reembolsar, Stripe no devuelve la comisión.</p>
      </section>

      <section class="box">
        <div class="box-head">
          <h2>Exportar informe</h2>
        </div>
        <p class="hint">PDF con el resumen, el desglose por país y cada donación (fecha, hora, país, método, comisión y neto). Pensado para enseñarlo a quien lo pida. Nunca incluye correos electrónicos.</p>
        <div class="export-row">
          <a class="btn btn-small" href="?export=pdf">Descargar PDF (anónimo)</a>
          <a class="btn btn-small btn-outline" href="?export=pdf&amp;names=1">PDF con nombres</a>
          <a class="btn btn-small btn-outline" href="?export=csv">Excel / CSV completo</a>
        </div>
        <p class="hint">El CSV incluye correos y mensajes: es solo para los organizadores.</p>
      </section>

      <?php if ($st['by_day']): ?>
      <section class="box">
        <h2>Evolución por día (neto)</h2>
        <div class="chart">
          <?php foreach ($st['by_day'] as $day => $v): $t = new DateTimeImmutable($day); ?>
            <div class="chart-row">
              <span class="chart-label"><?= e($t->format('d/m')) ?></span>
              <span class="chart-bar"><i style="width: <?= round($v['net'] * 100 / $maxDay, 1) ?>%"></i></span>
              <span class="chart-value"><?= money($v['net']) ?> <small>(<?= $v['count'] ?>)</small></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="two-cols">
        <section class="box">
          <h2>Por país</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>País</th><th class="num">Nº</th><th class="num">Donado</th><th class="num">Neto</th></tr></thead>
              <tbody>
              <?php foreach ($st['by_country'] as $code => $v): ?>
                <tr><td><?= e(country_name((string) $code)) ?></td><td class="num"><?= $v['count'] ?></td><td class="num"><?= money($v['amount']) ?></td><td class="num"><?= money($v['net']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
        <section class="box">
          <h2>Por método de pago</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Método</th><th class="num">Nº</th><th class="num">Donado</th><th class="num">Neto</th></tr></thead>
              <tbody>
              <?php foreach ($st['by_method'] as $m => $v): ?>
                <tr><td><?= e($m) ?></td><td class="num"><?= $v['count'] ?></td><td class="num"><?= money($v['amount']) ?></td><td class="num"><?= money($v['net']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section class="box">
        <h2>Hora del día en que se dona</h2>
        <div class="hours">
          <?php foreach ($st['by_hour'] as $h => $n): ?>
            <div class="hour" title="<?= $h ?>:00 – <?= $n ?> donaciones"><i style="height: <?= round($n * 100 / $maxHour) ?>%"></i><span><?= $h ?></span></div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="box">
        <h2>Detalle de cada transacción</h2>
        <?php if (!$list): ?><p class="hint">Aún no hay donaciones.</p><?php else: ?>
        <div class="table-wrap">
          <table class="tx">
            <thead><tr><th>Fecha</th><th>Hora</th><th>País</th><th>Método</th><th>Nombre</th><th class="num">Donado</th><th class="num">Comisión</th><th class="num">Neto</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $d): $t = local_time((int) $d['created']); $ref = (int) ($d['refunded'] ?? 0); ?>
              <tr class="<?= $d['amount'] <= 0 ? 'refunded' : '' ?>">
                <td><?= e($t->format('d/m/Y')) ?></td>
                <td><?= e($t->format('H:i:s')) ?></td>
                <td><?= e(country_name(donation_country($d))) ?><?php if (($d['card_country'] ?? '') !== '' && $d['card_country'] !== ($d['country'] ?? '')): ?><br><small class="hint">tarjeta: <?= e(country_name($d['card_country'])) ?></small><?php endif; ?></td>
                <td><?= e(method_detail($d)) ?></td>
                <td><?= e($d['name'] ?: '—') ?><?php if (($d['email'] ?? '') !== ''): ?><br><small class="hint"><?= e($d['email']) ?></small><?php endif; ?></td>
                <td class="num"><?= money((int) ($d['gross'] ?? $d['amount'])) ?></td>
                <td class="num"><?= isset($d['fee']) ? '− ' . money((int) $d['fee']) : '<span class="hint">pendiente</span>' ?></td>
                <td class="num"><strong><?= money(donation_net($d)) ?></strong></td>
                <td><?php if ($d['amount'] <= 0): ?><span class="tag tag-error">Reembolsada</span><?php elseif ($ref): ?><span class="tag tag-enviando">Reembolso parcial <?= money($ref) ?></span><?php else: ?><span class="tag tag-enviado">Completada</span><?php endif; ?></td>
                <td><a href="<?= e($dash . rawurlencode($d['id'])) ?>" target="_blank" rel="noopener noreferrer">Stripe ↗</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5">Total (<?= $st['count'] ?> donaciones)</td><td class="num"><?= money($st['gross']) ?></td><td class="num">− <?= money($st['fees']) ?></td><td class="num"><?= money($st['net']) ?></td><td colspan="2"></td></tr></tfoot>
          </table>
        </div>
        <p class="hint">Horas en hora peninsular española. País = país de facturación indicado al pagar (o, si falta, el del banco de la tarjeta).</p>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'updates'):
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
              <form method="post" data-confirm="¿Eliminar esta actualización?">
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
              <thead><tr><th>Fecha</th><th>Nombre</th><th>Email</th><th class="num">Importe</th><th>Agradecimiento</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($list as $d): ?>
                <tr class="<?= $d['amount'] <= 0 ? 'refunded' : '' ?>">
                  <td><?= e(date_es((int) $d['created'], true)) ?></td>
                  <td><?= e($d['name'] ?: '—') ?></td>
                  <td><?= e($d['email'] ?: '—') ?></td>
                  <td class="num"><?= $d['amount'] > 0 ? money((int) $d['amount']) : 'Reembolsado' ?></td>
                  <td>
                    <?php $th = $d['thanks'] ?? null; ?>
                    <?php if ($th): ?>
                      <span class="tag tag-<?= e($th['status']) ?>" title="<?= e($th['error'] ?? date_es((int) $th['at'], true)) ?>"><?= e(ucfirst($th['status'])) ?></span>
                    <?php elseif (($d['email'] ?? '') === ''): ?><span class="hint">Sin correo</span>
                    <?php else: ?><span class="hint">Pendiente</span><?php endif; ?>
                    <?php if (($d['email'] ?? '') !== '' && $d['amount'] > 0): ?>
                      <form method="post" class="inline" data-confirm="<?= e('¿Enviar el agradecimiento a ' . $d['email'] . '?') ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="tab" value="donations">
                        <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                        <button name="action" value="thanks_resend" class="link"><?= $th ? 'Reenviar' : 'Enviar' ?></button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td><a href="<?= e($dash . rawurlencode($d['id'])) ?>" target="_blank" rel="noopener">Stripe ↗</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td colspan="3">Total</td><td class="num"><?= money($p['raised']) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'settings'): $photo = photo_url($s); ?>
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
              <form method="post" data-confirm="¿Quitar la foto subida y volver a la original?">
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
          <label>La barra de la web muestra
            <select name="bar_mode">
              <option value="net" <?= $s['bar_mode'] === 'net' ? 'selected' : '' ?>>El dinero real que llega a la cuenta (descontando comisiones de Stripe)</option>
              <option value="gross" <?= $s['bar_mode'] === 'gross' ? 'selected' : '' ?>>Lo donado (sin descontar comisiones)</option>
            </select>
          </label>
          <button class="btn btn-small">Guardar ajustes</button>
        </form>
      </section>
    <?php elseif ($tab === 'messages'):
      $withMsg = array_values(array_filter(donations(), fn($d) => trim($d['message'] ?? '') !== ''));
    ?>
      <section class="box">
        <h2>Mensajes de los donantes</h2>
        <p class="hint">Al donar, cada persona puede dejar un mensaje para la familia (opcional). Aquí decides cuáles se muestran en la web, en la sección «Mensajes de cariño». Ninguno se publica sin tu aprobación.</p>
        <?php if (!$withMsg): ?><p class="hint">Todavía no hay mensajes.</p><?php endif; ?>
        <?php foreach ($withMsg as $d): $pub = !empty($d['message_public']); ?>
          <article class="item">
            <div>
              <p class="item-date"><?= e(date_es((int) $d['created'])) ?> · <?= e($d['name'] ?: 'Anónimo') ?> <?php if ($pub): ?><span class="tag tag-enviado">Publicado</span><?php endif; ?></p>
              <p class="item-body quote">«<?= nl2br(e($d['message'])) ?>»</p>
            </div>
            <div class="item-actions">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="tab" value="messages">
                <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <button name="action" value="<?= $pub ? 'message_hide' : 'message_publish' ?>" class="link"><?= $pub ? 'Ocultar de la web' : 'Publicar en la web' ?></button>
              </form>
              <form method="post" data-confirm="¿Eliminar este mensaje? No se puede deshacer.">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="tab" value="messages">
                <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                <button name="action" value="message_delete" class="link danger">Eliminar</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

    <?php elseif ($tab === 'mail'):
      $t = mail_templates();
      $draft = $_SESSION['draft'] ?? ['subject' => '', 'body' => ''];
      $log = store_read('mail_log', []);
      $testTo = (string) cfg('notify_email');
      $nRecipients = broadcast_recipients();
    ?>
      <section class="box">
        <h2>Correo de agradecimiento automático</h2>
        <p class="hint">Se envía solo, una vez, a cada persona que dona (al correo que pone al pagar).</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="templates_save">
          <input type="hidden" name="tab" value="mail">
          <label class="check"><input type="checkbox" name="thanks_enabled" value="1" <?= $t['thanks_enabled'] ? 'checked' : '' ?>> Enviar agradecimiento automático</label>
          <label>Asunto <input name="thanks_subject" value="<?= e($t['thanks_subject']) ?>" required></label>
          <label>Texto <textarea name="thanks_body" rows="11" required><?= e($t['thanks_body']) ?></textarea></label>
          <p class="hint">Puedes usar: <code>{nombre}</code> (nombre de pila), <code>{importe}</code>, <code>{fecha}</code>, <code>{recaudado}</code>, <code>{meta}</code>, <code>{enlace}</code> (dirección de la web). Línea en blanco = nuevo párrafo.</p>
          <button class="btn btn-small">Guardar</button>
        </form>
        <form method="post" class="test-row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="tab" value="mail">
          <input type="email" name="to" value="<?= e($testTo) ?>" placeholder="tu@correo.com" required>
          <button name="action" value="thanks_sample" class="btn btn-small btn-outline">Enviarme un ejemplo</button>
        </form>
      </section>

      <section class="box">
        <h2>Enviar un mensaje a los donantes</h2>
        <p class="hint">Para contar novedades o dar las gracias al cerrar la colecta. Se envía por separado a cada persona (nadie ve el correo de los demás). Destinatarios ahora: <strong><?= $nRecipients ?></strong>.</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="broadcast">
          <input type="hidden" name="tab" value="mail">
          <label>Asunto <input name="subject" value="<?= e($draft['subject']) ?>" required placeholder="Ej. Hemos llegado a la meta"></label>
          <label>Mensaje <textarea name="body" rows="8" required placeholder="Hola {nombre},&#10;&#10;…"><?= e($draft['body']) ?></textarea></label>
          <p class="hint">Mismos marcadores que arriba (<code>{importe}</code> = lo que aportó cada persona).</p>
          <div class="test-row">
            <input type="email" name="to" value="<?= e($testTo) ?>" placeholder="tu@correo.com">
            <button name="mode" value="test" class="btn btn-small btn-outline">Enviarme una prueba</button>
          </div>
          <label class="check"><input type="checkbox" name="confirm" value="1"> Confirmo que quiero enviarlo a <?= $nRecipients ?> donantes</label>
          <button name="mode" value="all" class="btn btn-small" <?= $nRecipients ? '' : 'disabled' ?>>Enviar a todos los donantes</button>
        </form>
      </section>

      <section class="box">
        <h2>Registro de correos</h2>
        <?php if (!$log): ?><p class="hint">Todavía no se ha enviado ningún correo.</p><?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Fecha</th><th>Tipo</th><th>Para</th><th>Asunto</th><th>Estado</th></tr></thead>
              <tbody>
              <?php foreach (array_slice($log, 0, 200) as $l): ?>
                <tr>
                  <td><?= e(date_es((int) $l['at'], true)) ?></td>
                  <td><?= e(ucfirst($l['type'])) ?></td>
                  <td><?= e($l['to']) ?></td>
                  <td class="wrap"><?= e($l['subject']) ?></td>
                  <td class="wrap"><span class="tag tag-<?= e($l['status']) ?>"><?= e(ucfirst($l['status'])) ?></span><?php if ($l['status'] === 'error'): ?><br><span class="hint"><?= e($l['detail']) ?></span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($tab === 'connections'):
      $in = store_read('integrations', []);
      $secretField = function (string $key, string $label, string $placeholder, string $help) use ($in) {
          $current = (string) cfg($key);
          $fromFile = $current !== '' && (string) ($in[$key] ?? '') === '';
          ?>
          <label><?= e($label) ?>
            <input type="password" name="<?= e($key) ?>" autocomplete="new-password" placeholder="<?= e($current !== '' ? 'Guardada: ' . mask_secret($current) . ' (deja vacío para mantenerla)' : $placeholder) ?>">
          </label>
          <p class="hint field-help"><?= $help ?><?php if ($fromFile): ?> Ahora se usa la de <code>config.php</code>.<?php endif; ?></p>
          <?php if ((string) ($in[$key] ?? '') !== ''): ?><label class="check"><input type="checkbox" name="clear_<?= e($key) ?>" value="1"> Borrar la clave guardada</label><?php endif; ?>
          <?php
      };
    ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="integrations_save">
        <input type="hidden" name="tab" value="connections">

        <section class="box">
          <h2>Dirección de la web</h2>
          <label>URL pública <input type="url" name="site_url" value="<?= e((string) cfg('site_url')) ?>" placeholder="https://sergio.tudominio.com"></label>
          <p class="hint field-help">Se usa en los pagos y en los enlaces de los correos. Fijarla impide que alguien manipule esos enlaces.</p>
        </section>

        <section class="box">
          <div class="box-head">
            <h2>Stripe (pagos)</h2>
            <?php if (stripe_ready()): ?><span class="tag tag-<?= stripe_test_mode() ? 'error' : 'enviado' ?>"><?= stripe_test_mode() ? 'Modo de prueba' : 'Modo real' ?></span><?php endif; ?>
          </div>
          <?php $secretField('stripe_secret_key', 'Clave secreta', 'sk_live_… o sk_test_…', 'En Stripe: <em>Desarrolladores → Claves de API → Clave secreta</em>. Usa la de la cuenta exclusiva de la colecta.'); ?>
          <?php $secretField('stripe_webhook_secret', 'Secreto del webhook', 'whsec_…', 'En Stripe: <em>Desarrolladores → Webhooks → Añadir destino</em> con la URL <code>' . e(site_url('webhook.php')) . '</code> y los eventos <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code> y <code>charge.refunded</code>.'); ?>
          <label>ID de producto <span class="hint">(opcional)</span>
            <input name="stripe_product_id" value="<?= e((string) ($in['stripe_product_id'] ?? '')) ?>" placeholder="prod_…">
          </label>
          <p class="hint field-help">Las donaciones de prueba y las reales se cuentan por separado: al cambiar a la clave real, la barra solo muestra las reales.</p>
        </section>

        <section class="box">
          <div class="box-head">
            <h2>Resend (correos)</h2>
            <?php if (mail_ready()): ?><span class="tag tag-enviado">Configurado</span><?php endif; ?>
          </div>
          <?php $secretField('resend_api_key', 'API key', 're_…', 'En resend.com: <em>API Keys → Create API Key</em> (permiso «Sending access»).'); ?>
          <div class="row">
            <label class="grow">Correo remitente
              <input type="email" name="mail_from_email" value="<?= e((string) cfg('mail_from_email')) ?>" placeholder="memoria@tudominio.com">
            </label>
            <label class="grow">Nombre del remitente
              <input name="mail_from_name" value="<?= e((string) cfg('mail_from_name')) ?>" placeholder="Amigos de Sergio">
            </label>
          </div>
          <p class="hint field-help">El dominio del remitente tiene que estar verificado en Resend (<em>Domains</em>); si no, los correos fallarán.</p>
          <div class="row">
            <label class="grow">Responder a <span class="hint">(opcional)</span>
              <input type="email" name="mail_reply_to" value="<?= e((string) cfg('mail_reply_to')) ?>" placeholder="tu@correo.com">
            </label>
            <label class="grow">Avisarme de cada donación en <span class="hint">(opcional)</span>
              <input type="email" name="notify_email" value="<?= e((string) cfg('notify_email')) ?>" placeholder="tu@correo.com">
            </label>
          </div>
        </section>
        <button class="btn btn-small save-connections">Guardar conexiones</button>
      </form>

      <section class="box tests">
        <h2>Comprobar</h2>
        <form method="post" class="test-row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="tab" value="connections">
          <button name="action" value="stripe_test" class="btn btn-small btn-outline" <?= stripe_ready() ? '' : 'disabled' ?>>Probar conexión con Stripe</button>
        </form>
        <form method="post" class="test-row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="tab" value="connections">
          <input type="email" name="to" value="<?= e((string) cfg('notify_email')) ?>" placeholder="tu@correo.com" required>
          <button name="action" value="mail_test" class="btn btn-small btn-outline" <?= mail_ready() ? '' : 'disabled' ?>>Enviar correo de prueba</button>
        </form>
      </section>

      <section class="box">
        <h2>Contraseña del panel</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="password_change">
          <input type="hidden" name="tab" value="connections">
          <div class="row">
            <label class="grow">Actual <input type="password" name="current" required autocomplete="current-password"></label>
            <label class="grow">Nueva (mín. 10) <input type="password" name="new" minlength="10" required autocomplete="new-password"></label>
            <label class="grow">Repite la nueva <input type="password" name="new2" minlength="10" required autocomplete="new-password"></label>
          </div>
          <button class="btn btn-small btn-outline">Cambiar contraseña</button>
        </form>
      </section>
    <?php endif; ?>
  </main>
<?php endif; ?>
</body>
</html>
