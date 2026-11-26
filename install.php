<?php
/**
 * Instalador: crea la tabla en MySQL, guarda la configuración y la contraseña
 * del panel. Solo funciona mientras el sitio no está instalado y después se borra solo.
 */
require __DIR__ . '/lib/bootstrap.php';

$configFile = APP_ROOT . '/config.php';
if (is_file($configFile)) {
    http_response_code(404);
    exit('No encontrado.');
}

session_name('memorial_install');
session_set_cookie_params(['httponly' => true, 'secure' => is_https(), 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Requisitos ---------- */
$checks = [
    ['PHP 8.1 o superior', PHP_VERSION_ID >= 80100, 'Tu versión: ' . PHP_VERSION . '. Cámbiala en cPanel → «Seleccionar versión de PHP».'],
    ['Extensión cURL (pagos y correos)', extension_loaded('curl'), 'Actívala en cPanel → «Seleccionar versión de PHP» → Extensiones.'],
    ['Extensión PDO MySQL (base de datos)', extension_loaded('pdo_mysql'), 'Actívala en cPanel → «Seleccionar versión de PHP» → Extensiones.'],
    ['Extensión mbstring', extension_loaded('mbstring'), 'Actívala en cPanel → «Seleccionar versión de PHP» → Extensiones.'],
    ['Se puede escribir la configuración', is_writable(APP_ROOT), 'La carpeta de la web debe tener permisos 755.'],
    ['Se pueden subir fotos (carpeta uploads)', is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR), 'La carpeta uploads debe tener permisos 755.'],
];
$recommended = [
    ['Extensión GD (procesar fotos)', extension_loaded('gd')],
    ['Conexión segura https', is_https()],
];
$ok = !in_array(false, array_column($checks, 1), true);

$errors = [];
$done = false;
$v = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_prefix' => 'memorial_',
    'site_url' => rtrim(site_url(''), '/'),
];

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) {
        $v[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');

    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'La sesión expiró. Vuelve a enviar el formulario.';
    }
    if ($v['db_name'] === '' || $v['db_user'] === '') {
        $errors[] = 'Completa el nombre de la base de datos y el usuario.';
    }
    if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $v['db_prefix'])) {
        $errors[] = 'El prefijo solo puede tener letras, números y guion bajo.';
    }
    if (!ctype_digit($v['db_port'])) {
        $errors[] = 'El puerto debe ser un número (normalmente 3306).';
    }
    if (!filter_var($v['site_url'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~', $v['site_url'])) {
        $errors[] = 'La dirección de la web no es válida (ej. https://sergio.tudominio.com).';
    }
    if (mb_strlen($pass) < 10) {
        $errors[] = 'La contraseña del panel debe tener al menos 10 caracteres.';
    } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
        $errors[] = 'Las contraseñas del panel no coinciden.';
    }

    if (!$errors) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $v['db_host'], (int) $v['db_port'], $v['db_name']),
                $v['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );
            db_install($pdo, $v['db_prefix']);
            $t = '`' . $v['db_prefix'] . 'store`';
            $pdo->prepare("REPLACE INTO $t (name, data) VALUES ('admin', ?)")
                ->execute([json_encode(['password_hash' => password_hash($pass, PASSWORD_DEFAULT)])]);
        } catch (Throwable $ex) {
            $msg = $ex->getMessage();
            if (str_contains($msg, 'Access denied')) {
                $errors[] = 'MySQL rechazó el usuario o la contraseña. En cPanel → «Bases de datos MySQL», comprueba que el usuario está añadido a la base de datos con «Todos los privilegios».';
            } elseif (str_contains($msg, 'Unknown database')) {
                $errors[] = 'La base de datos no existe. Revisa el nombre completo (en cPanel suele llevar un prefijo, ej. «usuario_memorial»).';
            } else {
                $errors[] = 'No se pudo conectar con MySQL: ' . $msg;
            }
        }
    }

    if (!$errors) {
        $config = [
            'site_url' => rtrim($v['site_url'], '/'),
            'db_host' => $v['db_host'],
            'db_port' => (int) $v['db_port'],
            'db_name' => $v['db_name'],
            'db_user' => $v['db_user'],
            'db_pass' => $dbPass,
            'db_prefix' => $v['db_prefix'],
            'campaign_id' => 'memoria-sergio-elio-alderete',
            'installed_at' => date('c'),
        ];
        $php = "<?php\n// Generado por el instalador el " . date('Y-m-d H:i') . ". No compartas este archivo.\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configFile, $php, LOCK_EX) === false) {
            $errors[] = 'No se pudo guardar config.php. Revisa los permisos de la carpeta.';
        } else {
            @chmod($configFile, 0640);
            $done = true;
            $_SESSION = [];
            session_destroy();
            $selfDeleted = @unlink(__FILE__);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalación</title>
<link rel="stylesheet" href="assets/css/memorial.css">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="admin">
<main class="install">
  <p class="eyebrow">En memoria de Sergio Elio Alderete</p>
  <h1>Instalación</h1>

<?php if ($done): ?>
  <section class="box">
    <h2>¡Listo! La web está instalada</h2>
    <p>La base de datos está creada y la configuración guardada.</p>
    <?php if (empty($selfDeleted)): ?>
      <p class="flash flash-warn">Por seguridad, borra el archivo <code>install.php</code> desde el administrador de archivos (ya está bloqueado, pero mejor eliminarlo).</p>
    <?php else: ?>
      <p class="hint">El instalador se ha borrado solo, por seguridad.</p>
    <?php endif; ?>
    <p><strong>Siguiente paso:</strong> entra al panel con tu contraseña y, en <em>Conexiones</em>, pega las claves de Stripe y de Resend.</p>
    <p><a class="btn btn-small" href="admin/">Ir al panel</a> <a class="cancel" href="./">Ver la web</a></p>
  </section>
<?php else: ?>

  <section class="box">
    <h2>1. Comprobación del servidor</h2>
    <ul class="checks">
      <?php foreach ($checks as [$label, $pass, $help]): ?>
        <li class="<?= $pass ? 'ok' : 'ko' ?>"><?= $pass ? '✓' : '✕' ?> <?= e($label) ?><?php if (!$pass): ?><br><span class="hint"><?= e($help) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
      <?php foreach ($recommended as [$label, $pass]): ?>
        <li class="<?= $pass ? 'ok' : 'warn' ?>"><?= $pass ? '✓' : '!' ?> <?= e($label) ?> <?= $pass ? '' : '<span class="hint">(recomendado)</span>' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($ok): ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <?php foreach ($errors as $err): ?><p class="flash flash-error"><?= e($err) ?></p><?php endforeach; ?>

    <section class="box">
      <h2>2. Base de datos MySQL</h2>
      <p class="hint">Créala antes en cPanel → <em>Bases de datos MySQL</em>: crea una base de datos, crea un usuario con contraseña y añade el usuario a la base de datos con <strong>todos los privilegios</strong>.</p>
      <div class="row">
        <label class="grow">Nombre de la base de datos <input name="db_name" value="<?= e($v['db_name']) ?>" required placeholder="usuario_memorial"></label>
        <label class="grow">Usuario <input name="db_user" value="<?= e($v['db_user']) ?>" required placeholder="usuario_memorial"></label>
      </div>
      <label>Contraseña del usuario de MySQL <input type="password" name="db_pass" autocomplete="new-password"></label>
      <details>
        <summary class="hint">Opciones avanzadas (normalmente no hace falta tocarlas)</summary>
        <div class="row">
          <label class="grow">Servidor <input name="db_host" value="<?= e($v['db_host']) ?>" required></label>
          <label>Puerto <input name="db_port" value="<?= e($v['db_port']) ?>" required></label>
          <label>Prefijo de tablas <input name="db_prefix" value="<?= e($v['db_prefix']) ?>"></label>
        </div>
      </details>
    </section>

    <section class="box">
      <h2>3. Web y acceso al panel</h2>
      <label>Dirección de la web <input type="url" name="site_url" value="<?= e($v['site_url']) ?>" required></label>
      <p class="hint field-help">Se usa en los enlaces de los correos y en los pagos.</p>
      <div class="row">
        <label class="grow">Contraseña del panel <span class="hint">(mín. 10 caracteres)</span> <input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
        <label class="grow">Repite la contraseña <input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
      </div>
    </section>

    <button class="btn">Instalar</button>
  </form>
  <?php else: ?>
    <p class="flash flash-error">Corrige los puntos marcados con ✕ y recarga esta página.</p>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
