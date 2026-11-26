<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Configuración
 * ------------------------------------------------------------------ */
const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const UPLOAD_DIR = APP_ROOT . '/uploads';

function cfg(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = APP_ROOT . '/config.php';
        $config = is_file($file) ? (array) require $file : [];
        $config += (array) require APP_ROOT . '/config.example.php';
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
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
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

function store_read(string $name, $default = [])
{
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

/** Lee, modifica y guarda de forma atómica. Devuelve el nuevo valor. */
function store_update(string $name, callable $fn, $default = [])
{
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
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
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

/** Registra o actualiza un PaymentIntent (con latest_charge expandido). */
function record_payment_intent(array $pi, array $extra = []): bool
{
    if (($pi['metadata']['campaign'] ?? null) !== cfg('campaign_id')) {
        return false; // no es de esta colecta: se ignora
    }
    if (($pi['status'] ?? '') !== 'succeeded') {
        return false;
    }
    $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
    $amount = (int) ($pi['amount_received'] ?? 0) - (int) ($charge['amount_refunded'] ?? 0);
    $row = [
        'id' => $pi['id'],
        'amount' => max(0, $amount),
        'currency' => $pi['currency'] ?? 'eur',
        'created' => (int) ($pi['created'] ?? time()),
        'name' => $extra['name'] ?? ($charge['billing_details']['name'] ?? ''),
        'email' => $extra['email'] ?? ($charge['billing_details']['email'] ?? ($pi['receipt_email'] ?? '')),
    ];
    store_update('donations', function (array $all) use ($row) {
        $old = $all[$row['id']] ?? [];
        // conserva nombre/email ya conocidos si el nuevo dato viene vacío
        foreach (['name', 'email'] as $k) {
            if (($row[$k] ?? '') === '' && !empty($old[$k])) {
                $row[$k] = $old[$k];
            }
        }
        $all[$row['id']] = $row;
        return $all;
    });
    return true;
}

/** Comprueba una sesión de Checkout y la registra si está pagada. */
function record_checkout_session(string $sessionId): bool
{
    $s = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId), [
        'expand' => ['payment_intent.latest_charge'],
    ]);
    if (($s['metadata']['campaign'] ?? null) !== cfg('campaign_id') || ($s['payment_status'] ?? '') !== 'paid') {
        return false;
    }
    if (!is_array($s['payment_intent'] ?? null)) {
        return false;
    }
    return record_payment_intent($s['payment_intent'], [
        'name' => (string) ($s['customer_details']['name'] ?? ''),
        'email' => (string) ($s['customer_details']['email'] ?? ''),
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
        $params = ['query' => $query, 'limit' => 100, 'expand' => ['data.latest_charge']];
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

function donations(): array
{
    $list = array_values(store_read('donations', []));
    usort($list, fn($a, $b) => $b['created'] <=> $a['created']);
    return $list;
}

function progress(): array
{
    $raised = 0;
    $count = 0;
    foreach (store_read('donations', []) as $d) {
        if ($d['amount'] > 0) {
            $raised += (int) $d['amount'];
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

function photo_url(array $s): string
{
    if ($s['photo'] === '' || !is_file(UPLOAD_DIR . '/' . $s['photo'])) {
        // Foto por defecto incluida en el sitio (se puede reemplazar desde el panel)
        $default = 'assets/img/sergio.jpg';
        return is_file(APP_ROOT . '/' . $default) ? $default . '?v=' . filemtime(APP_ROOT . '/' . $default) : '';
    }
    return 'uploads/' . rawurlencode($s['photo']) . '?v=' . filemtime(UPLOAD_DIR . '/' . $s['photo']);
}
