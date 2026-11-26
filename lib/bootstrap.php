<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Configuración
 * ------------------------------------------------------------------ */
const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const UPLOAD_DIR = APP_ROOT . '/uploads';

/** Claves que se pueden configurar desde el panel (pestaña Conexiones). */
const INTEGRATION_KEYS = [
    'site_url', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_product_id',
    'resend_api_key', 'mail_from_email', 'mail_from_name', 'mail_reply_to', 'notify_email',
];

/* ------------------------------------------------------------------
 * Seguridad básica para todas las páginas
 * ------------------------------------------------------------------ */
ini_set('display_errors', '0');          // nunca mostrar errores (rutas, datos) al visitante
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; "
        . "form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
}

/**
 * Limitador de frecuencia por IP. Devuelve false si se superó el máximo
 * de $max acciones en $window segundos.
 */
function rate_limit(string $bucket, int $max, int $window): bool
{
    $key = $bucket . ':' . hash('sha256', client_ip());
    $now = time();
    $allowed = true;
    store_update('ratelimit', function ($all) use ($key, $max, $window, $now, &$allowed) {
        foreach ($all as $k => $times) { // limpieza
            $all[$k] = array_values(array_filter($times, fn($t) => $t > $now - 86400));
            if (!$all[$k]) {
                unset($all[$k]);
            }
        }
        $recent = array_filter($all[$key] ?? [], fn($t) => $t > $now - $window);
        if (count($recent) >= $max) {
            $allowed = false;
        } else {
            $all[$key][] = $now;
        }
        return $all;
    });
    return $allowed;
}

/** Configuración de archivos (config.php + valores por defecto). */
function file_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = APP_ROOT . '/config.php';
        $config = is_file($file) ? (array) require $file : [];
        $config += (array) require APP_ROOT . '/config.example.php';
    }
    return $config;
}

function cfg(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = file_config();
        // Las claves guardadas desde el panel (Conexiones) tienen prioridad
        foreach (store_read('integrations', []) as $k => $v) {
            if (in_array($k, INTEGRATION_KEYS, true) && $v !== '' && $v !== null) {
                $config[$k] = $v;
            }
        }
    }
    return $config[$key] ?? $default;
}

function stripe_ready(): bool
{
    return (string) cfg('stripe_secret_key') !== '';
}

function stripe_test_mode(): bool
{
    return str_starts_with((string) cfg('stripe_secret_key'), 'sk_test_')
        || str_starts_with((string) cfg('stripe_secret_key'), 'rk_test_');
}

function site_url(string $path = ''): string
{
    $base = rtrim((string) cfg('site_url'), '/');
    if ($base === '') {
        // Sin dirección configurada se deduce de la petición. Configúrala en
        // Conexiones para que nadie pueda falsear los enlaces de los correos.
        $https = is_https();
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if (str_ends_with($dir, '/admin')) {
            $dir = substr($dir, 0, -6);
        }
        $base = ($https ? 'https' : 'http') . '://' . $host . $dir;
    }
    return $base . '/' . ltrim($path, '/');
}

/* ------------------------------------------------------------------
 * Utilidades de presentación
 * ------------------------------------------------------------------ */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split("/\R{2,}/", trim($text)) as $p) {
        if (trim($p) !== '') {
            $out .= '<p>' . nl2br(e(trim($p))) . "</p>\n";
        }
    }
    return $out;
}

function money(int $cents): string
{
    $decimals = $cents % 100 === 0 ? 0 : 2;
    return number_format($cents / 100, $decimals, ',', '.') . ' €';
}

function date_es(int $ts, bool $withTime = false): string
{
    static $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $tz = new DateTimeZone('Europe/Madrid');
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $s = $d->format('j') . ' de ' . $m[(int) $d->format('n') - 1] . ' de ' . $d->format('Y');
    return $withTime ? $s . ', ' . $d->format('H:i') : $s;
}

/* ------------------------------------------------------------------
 * Almacenamiento en archivos (con bloqueo).
 * Se guardan como .php con una primera línea que corta la ejecución,
 * así nunca se pueden descargar aunque el servidor ignore .htaccess.
 * ------------------------------------------------------------------ */
const STORE_GUARD = "<?php http_response_code(404); exit; ?>\n";

function store_path(string $name): string
{
    return DATA_DIR . '/' . preg_replace('/[^a-z0-9_-]/', '', $name) . '.php';
}

function store_decode(string $raw, $default)
{
    if (str_starts_with($raw, STORE_GUARD)) {
        $raw = substr($raw, strlen(STORE_GUARD));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/* ---------- MySQL (modo normal tras instalar) ---------- */
function db(): ?PDO
{
    static $pdo = false;
    if ($pdo === false) {
        $pdo = null;
        $c = file_config();
        if ((string) ($c['db_name'] ?? '') !== '') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $c['db_host'] ?? 'localhost', (int) ($c['db_port'] ?? 3306), $c['db_name']);
            $pdo = new PDO($dsn, (string) ($c['db_user'] ?? ''), (string) ($c['db_pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    }
    return $pdo;
}

function db_table(): string
{
    return '`' . preg_replace('/[^A-Za-z0-9_]/', '', (string) (file_config()['db_prefix'] ?? 'memorial_')) . 'store`';
}

/** Crea la tabla (la usa el instalador; es idempotente). */
function db_install(PDO $pdo, string $prefix): void
{
    $t = '`' . preg_replace('/[^A-Za-z0-9_]/', '', $prefix) . 'store`';
    $pdo->exec("CREATE TABLE IF NOT EXISTS $t (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        data LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function store_read(string $name, $default = [])
{
    if ($pdo = db()) {
        $st = $pdo->prepare('SELECT data FROM ' . db_table() . ' WHERE name = ?');
        $st->execute([$name]);
        $raw = $st->fetchColumn();
        return $raw === false ? $default : store_decode((string) $raw, $default);
    }
    $file = store_path($name);
    if (!is_file($file)) {
        return $default;
    }
    $fh = fopen($file, 'rb');
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return store_decode((string) $raw, $default);
}

/** Lee, modifica y guarda de forma atómica (transacción con bloqueo). Devuelve el nuevo valor. */
function store_update(string $name, callable $fn, $default = [])
{
    if ($pdo = db()) {
        $t = db_table();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT IGNORE INTO $t (name, data) VALUES (?, 'null')")->execute([$name]);
            $st = $pdo->prepare("SELECT data FROM $t WHERE name = ? FOR UPDATE");
            $st->execute([$name]);
            $data = $fn(store_decode((string) $st->fetchColumn(), $default));
            $pdo->prepare("UPDATE $t SET data = ? WHERE name = ?")
                ->execute([json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $name]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        return $data;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    $fh = fopen(store_path($name), 'c+b');
    flock($fh, LOCK_EX);
    $data = store_decode((string) stream_get_contents($fh), $default);
    $data = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, STORE_GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

/** Hash de la contraseña del panel (la del instalador o la cambiada desde el panel). */
function admin_password_hash(): string
{
    return (string) (store_read('admin', [])['password_hash'] ?? cfg('admin_password_hash', ''));
}

/* Sin instalar: cualquier página lleva al instalador. */
if (PHP_SAPI !== 'cli' && !is_file(APP_ROOT . '/config.php') && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php') {
    if (is_file(APP_ROOT . '/install.php')) {
        header('Location: ' . site_url('install.php'), true, 302);
    } else {
        http_response_code(503);
        echo 'Sitio no instalado.';
    }
    exit;
}

/* ------------------------------------------------------------------
 * Ajustes editables desde el panel
 * ------------------------------------------------------------------ */
function default_settings(): array
{
    return [
        'name' => 'Sergio Elio Alderete',
        'dates' => '',
        'epitaph' => 'Siempre en nuestra memoria',
        'tribute' => "Hace unos días nos dejó Sergio. Quienes tuvimos la suerte de conocerlo sabemos lo mucho que deja en cada uno de nosotros.\n\nSus amigos queremos despedirlo como merece y acompañar a su familia en este momento tan difícil. Por eso abrimos esta colecta para cubrir los gastos del servicio funerario.",
        'transparency' => 'Todo lo recaudado se destinará a cubrir los gastos del servicio funerario de Sergio. En esta página iremos publicando cada avance del proceso.',
        'organizer' => 'Organizado por los amigos de Sergio',
        'goal_cents' => 200000,
        'donations_open' => true,
        'bar_mode' => 'net', // 'net' = lo que llega realmente a la cuenta; 'gross' = lo donado
        'photo' => '',
    ];
}

function settings(): array
{
    return store_read('settings', []) + default_settings();
}

/* ------------------------------------------------------------------
 * Actualizaciones del proceso
 * ------------------------------------------------------------------ */
function updates(): array
{
    $list = array_values(store_read('updates', []));
    usort($list, fn($a, $b) => ($b['date'] <=> $a['date']) ?: ($b['created'] <=> $a['created']));
    return $list;
}

/* ------------------------------------------------------------------
 * Stripe (API REST directa, sin librerías)
 * ------------------------------------------------------------------ */
function stripe_request(string $method, string $path, array $params = []): array
{
    $key = (string) cfg('stripe_secret_key');
    if ($key === '') {
        throw new RuntimeException('Stripe no está configurado (falta stripe_secret_key en config.php).');
    }
    $url = rtrim((string) cfg('stripe_api_base', 'https://api.stripe.com'), '/') . '/v1/' . ltrim($path, '/');
    $query = http_build_query($params, '', '&');
    if ($method === 'GET' && $query !== '') {
        $url .= '?' . $query;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $key . ':',
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('No se pudo conectar con Stripe: ' . $err);
    }
    $json = json_decode((string) $body, true);
    if ($status >= 400 || !is_array($json)) {
        throw new RuntimeException('Stripe: ' . ($json['error']['message'] ?? "error HTTP $status"));
    }
    return $json;
}

/* ------------------------------------------------------------------
 * Registro de donaciones (solo pagos con metadata campaign = campaign_id)
 * ------------------------------------------------------------------ */

/**
 * Registra o actualiza un PaymentIntent (con latest_charge.balance_transaction expandido).
 * Guarda bruto, comisión de Stripe, reembolsos y neto real que llega a la cuenta.
 */
function record_payment_intent(array $pi, array $extra = []): bool
{
    if (($pi['metadata']['campaign'] ?? null) !== cfg('campaign_id')) {
        return false; // no es de esta colecta: se ignora
    }
    if (($pi['status'] ?? '') !== 'succeeded' || !preg_match('/^pi_[A-Za-z0-9]+$/', (string) ($pi['id'] ?? ''))) {
        return false;
    }
    $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
    $bt = is_array($charge['balance_transaction'] ?? null) ? $charge['balance_transaction'] : null;
    $gross = (int) ($pi['amount_received'] ?? 0);
    $refunded = (int) ($charge['amount_refunded'] ?? 0);
    $pmd = $charge['payment_method_details'] ?? [];
    $card = $pmd['card'] ?? [];

    $row = [
        'id' => $pi['id'],
        'livemode' => (bool) ($pi['livemode'] ?? false),
        'gross' => $gross,
        'refunded' => $refunded,
        'amount' => max(0, $gross - $refunded),          // lo aportado (descontando reembolsos)
        'currency' => $pi['currency'] ?? 'eur',
        'created' => (int) ($pi['created'] ?? time()),
        // nombre que el donante escribió en la web; si no hay, el del pago
        'name' => normalize_person_name((string) (($pi['metadata']['donor_name'] ?? '') ?: ($extra['name'] ?? ($charge['billing_details']['name'] ?? '')))),
        'billing_name' => (string) ($extra['name'] ?? ($charge['billing_details']['name'] ?? '')),
        'anonymous' => ($pi['metadata']['donor_anonymous'] ?? '') === '1',
        'email' => $extra['email'] ?? ($charge['billing_details']['email'] ?? ($pi['receipt_email'] ?? '')),
        'country' => strtoupper((string) ($extra['country'] ?? ($charge['billing_details']['address']['country'] ?? ''))),
        'card_country' => strtoupper((string) ($card['country'] ?? '')),
        'method' => (string) ($pmd['type'] ?? ''),
        'brand' => (string) ($card['brand'] ?? ''),
        'last4' => (string) ($card['last4'] ?? ''),
        'wallet' => (string) ($card['wallet']['type'] ?? ''),
        'charge_id' => (string) ($charge['id'] ?? ''),
    ];
    if ($bt) {
        // Stripe no devuelve la comisión al reembolsar: neto = neto del cobro - reembolsado
        $row['fee'] = (int) ($bt['fee'] ?? 0);
        $row['net'] = (int) ($bt['net'] ?? 0) - $refunded;
        $row['fee_details'] = array_map(fn($f) => [
            'type' => (string) ($f['type'] ?? ''),
            'amount' => (int) ($f['amount'] ?? 0),
            'description' => (string) ($f['description'] ?? ''),
        ], $bt['fee_details'] ?? []);
        $row['settle_currency'] = (string) ($bt['currency'] ?? $row['currency']);
        $row['available_on'] = (int) ($bt['available_on'] ?? 0);
        $row['fee_pending'] = false;
    }
    if (isset($extra['message'])) {
        $row['message'] = mb_substr((string) $extra['message'], 0, 500);
    }
    $isNew = false;
    store_update('donations', function (array $all) use ($row, &$isNew) {
        $old = $all[$row['id']] ?? [];
        $isNew = !$old;
        if ($isNew) {
            $row['seq'] = microtime(true); // orden de llegada (desempate si dos pagos caen en el mismo segundo)
        }
        // conserva datos ya conocidos si el nuevo dato viene vacío
        foreach (['name', 'billing_name', 'email', 'message', 'country', 'card_country', 'method', 'brand', 'last4', 'wallet', 'charge_id'] as $k) {
            if (($row[$k] ?? '') === '' && !empty($old[$k])) {
                $row[$k] = $old[$k];
            }
        }
        if (!array_key_exists('fee', $row)) {
            if (isset($old['fee'])) {
                // comisión conocida de antes: recalcula el neto con los reembolsos actuales
                $row['net'] = (int) $old['net'] + (int) ($old['refunded'] ?? 0) - $row['refunded'];
            } else {
                $row['fee_pending'] = true; // se completará en la próxima sincronización
            }
        }
        // conserva el estado de correos y la moderación del mensaje
        $all[$row['id']] = $row + $old;
        return $all;
    });
    if ($row['amount'] > 0) {
        require_once __DIR__ . '/mail.php';
        if ($isNew) {
            notify_new_donation($row['id']);
        }
        maybe_send_thanks($row['id']);
    }
    return true;
}

/** Neto real de una donación (si la comisión aún no se conoce, se usa el bruto). */
function donation_net(array $d): int
{
    return isset($d['net']) ? (int) $d['net'] : (int) $d['amount'];
}

/** Comprueba una sesión de Checkout y la registra si está pagada. */
function record_checkout_session(string $sessionId): bool
{
    $s = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId), [
        'expand' => ['payment_intent.latest_charge.balance_transaction'],
    ]);
    if (($s['metadata']['campaign'] ?? null) !== cfg('campaign_id') || ($s['payment_status'] ?? '') !== 'paid') {
        return false;
    }
    if (!is_array($s['payment_intent'] ?? null)) {
        return false;
    }
    $message = '';
    foreach ($s['custom_fields'] ?? [] as $f) {
        if (($f['key'] ?? '') === 'mensaje') {
            $message = trim((string) ($f['text']['value'] ?? ''));
        }
    }
    return record_payment_intent($s['payment_intent'], [
        'name' => (string) ($s['customer_details']['name'] ?? ''),
        'email' => (string) ($s['customer_details']['email'] ?? ''),
        'message' => $message,
        'country' => (string) ($s['customer_details']['address']['country'] ?? ''),
    ]);
}

/**
 * Reconstruye el registro consultando a Stripe todos los pagos de la campaña.
 * Recoge pagos que el webhook no haya avisado y aplica reembolsos.
 */
function sync_with_stripe(): int
{
    $query = sprintf("metadata['campaign']:'%s' AND status:'succeeded'", cfg('campaign_id'));
    $page = null;
    $n = 0;
    do {
        $params = ['query' => $query, 'limit' => 100, 'expand' => ['data.latest_charge.balance_transaction']];
        if ($page) {
            $params['page'] = $page;
        }
        $res = stripe_request('GET', 'payment_intents/search', $params);
        foreach ($res['data'] ?? [] as $pi) {
            if (record_payment_intent($pi)) {
                $n++;
            }
        }
        $page = !empty($res['has_more']) ? ($res['next_page'] ?? null) : null;
    } while ($page);
    store_update('state', fn($s) => ['last_sync' => time(), 'last_sync_ok' => true] + $s);
    return $n;
}

/** Sincroniza en segundo plano como mucho cada 10 minutos (red de seguridad). */
function maybe_auto_sync(): void
{
    if (!stripe_ready()) {
        return;
    }
    $state = store_read('state', []);
    if (time() - (int) ($state['last_sync_attempt'] ?? 0) < 600) {
        return;
    }
    store_update('state', fn($s) => ['last_sync_attempt' => time()] + $s);
    try {
        sync_with_stripe();
    } catch (Throwable $e) {
        error_log('memorial sync: ' . $e->getMessage());
    }
}

/** Solo las donaciones del modo actual (prueba o real) de Stripe. */
function mode_donations(): array
{
    $live = !stripe_test_mode();
    return array_filter(store_read('donations', []), fn($d) => (bool) ($d['livemode'] ?? false) === $live);
}

function donations(): array
{
    $list = array_values(mode_donations());
    usort($list, fn($a, $b) => ($b['created'] <=> $a['created']) ?: (($b['seq'] ?? 0) <=> ($a['seq'] ?? 0)));
    return $list;
}

function progress(): array
{
    $raised = 0;
    $count = 0;
    $useNet = settings()['bar_mode'] === 'net';
    foreach (mode_donations() as $d) {
        if ($d['amount'] > 0) {
            $raised += $useNet ? max(0, donation_net($d)) : (int) $d['amount'];
            $count++;
        }
    }
    $goal = max(1, (int) settings()['goal_cents']);
    return [
        'raised' => $raised,
        'goal' => $goal,
        'count' => $count,
        'percent' => min(100, (int) floor($raised * 100 / $goal)),
    ];
}

/** Limpia un nombre de persona: sin caracteres raros, espacios normales y mayúscula inicial. */
function normalize_person_name(string $name): string
{
    $name = preg_replace('/[^\p{L}\p{M} .\'’-]+/u', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $name = mb_substr($name, 0, 60);
    if ($name === '') {
        return '';
    }
    $words = explode(' ', mb_convert_case(mb_strtolower($name), MB_CASE_TITLE));
    foreach ($words as $i => $w) { // partículas en minúscula: «de la Fuente», «del Río»
        if ($i > 0 && in_array(mb_strtolower($w), ['de', 'del', 'la', 'las', 'los', 'y', 'da', 'do', 'dos', 'van', 'von'], true)) {
            $words[$i] = mb_strtolower($w);
        }
    }
    return implode(' ', $words);
}

/** Nombre que se puede mostrar en público (respeta el anonimato y lo que oculte el admin). */
function public_donor_name(array $d): string
{
    if (!empty($d['anonymous']) || !empty($d['name_hidden']) || trim((string) ($d['name'] ?? '')) === '') {
        return 'Anónimo';
    }
    return (string) $d['name'];
}

/** Hace cuánto: «hace 5 minutos», «hace 2 horas», «ayer»… */
function time_ago(int $ts): string
{
    $d = max(0, time() - $ts);
    if ($d < 60) {
        return 'hace un momento';
    }
    if ($d < 3600) {
        $m = intdiv($d, 60);
        return 'hace ' . $m . ($m === 1 ? ' minuto' : ' minutos');
    }
    if ($d < 86400) {
        $h = intdiv($d, 3600);
        return 'hace ' . $h . ($h === 1 ? ' hora' : ' horas');
    }
    $days = intdiv($d, 86400);
    return $days === 1 ? 'ayer' : 'hace ' . $days . ' días';
}

/** Última donación válida (para «Última donación: Juan Pérez»). */
function last_donation(): ?array
{
    foreach (donations() as $d) {
        if ((int) $d['amount'] > 0) {
            return ['name' => public_donor_name($d), 'ago' => time_ago((int) $d['created']), 'created' => (int) $d['created']];
        }
    }
    return null;
}

/** Mensajes de los donantes que el admin ha decidido publicar. */
function public_messages(): array
{
    $list = array_filter(donations(), fn($d) => !empty($d['message_public']) && trim($d['message'] ?? '') !== '' && $d['amount'] > 0);
    return array_values($list);
}

function mask_secret(string $v): string
{
    if ($v === '') {
        return '';
    }
    return strlen($v) <= 12 ? str_repeat('•', 8) : substr($v, 0, 8) . '…' . substr($v, -4);
}

function photo_url(array $s): string
{
    if ($s['photo'] === '' || !is_file(UPLOAD_DIR . '/' . $s['photo'])) {
        // Foto por defecto incluida en el sitio (se puede reemplazar desde el panel)
        $default = 'assets/img/sergio.jpg';
        return is_file(APP_ROOT . '/' . $default) ? $default . '?v=' . filemtime(APP_ROOT . '/' . $default) : '';
    }
    return 'uploads/' . rawurlencode($s['photo']) . '?v=' . filemtime(UPLOAD_DIR . '/' . $s['photo']);
}
