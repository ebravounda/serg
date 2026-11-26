<?php
require __DIR__ . '/lib/bootstrap.php';

function back(string $msg): never
{
    header('Location: index.php?error=' . rawurlencode($msg) . '#donar', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#donar', true, 303);
    exit;
}
// Anti-abuso: campo trampa para bots y límite de intentos por IP
// (frena las «pruebas de tarjetas robadas» que sufren las páginas de donación)
if (($_POST['website'] ?? '') !== '') {
    back('No pudimos procesar la solicitud.');
}
if (!rate_limit('checkout', 10, 3600)) {
    back('Has hecho demasiados intentos. Vuelve a intentarlo dentro de un rato.');
}
$s = settings();
if (!$s['donations_open'] || !stripe_ready()) {
    back('Las donaciones no están disponibles en este momento.');
}

$choice = (string) ($_POST['amount'] ?? '');
$raw = $choice === 'otro' ? (string) ($_POST['custom'] ?? '') : $choice;
$raw = str_replace(',', '.', trim($raw));
if (!is_numeric($raw)) {
    back('Indica un importe válido.');
}
$cents = (int) round((float) $raw * 100);
$min = (int) cfg('min_amount');
$max = (int) cfg('max_amount');
if ($cents < $min * 100 || $cents > $max * 100) {
    back("El importe debe estar entre $min € y " . number_format($max, 0, ',', '.') . ' €.');
}

// Nombre y apellido del donante (obligatorio) y opción de aparecer como anónimo
$donorName = normalize_person_name((string) ($_POST['donor_name'] ?? ''));
if (mb_strlen($donorName) < 3 || count(explode(' ', $donorName)) < 2) {
    back('Escribe tu nombre y apellido.');
}
$anonymous = !empty($_POST['anonymous']) ? '1' : '0';

$campaign = (string) cfg('campaign_id');
$label = 'Donación en memoria de ' . $s['name'];

$priceData = ['currency' => cfg('currency'), 'unit_amount' => $cents];
if (cfg('stripe_product_id')) {
    $priceData['product'] = cfg('stripe_product_id');
} else {
    $priceData['product_data'] = [
        'name' => $label,
        'description' => 'Colecta para el servicio funerario',
        'metadata' => ['campaign' => $campaign],
    ];
}

$params = [
    'mode' => 'payment',
    'submit_type' => 'donate',
    'locale' => 'es',
    'line_items' => [['price_data' => $priceData, 'quantity' => 1]],
    // La etiqueta "campaign" es lo que separa este dinero del resto de la cuenta.
    'metadata' => ['campaign' => $campaign, 'donor_name' => $donorName, 'donor_anonymous' => $anonymous],
    'payment_intent_data' => [
        'description' => $label . ' — ' . $donorName,
        'metadata' => ['campaign' => $campaign, 'donor_name' => $donorName, 'donor_anonymous' => $anonymous],
    ],
    // Mensaje opcional para la familia (se gestiona en el panel → Mensajes)
    'custom_fields' => [[
        'key' => 'mensaje',
        'label' => ['type' => 'custom', 'custom' => 'Mensaje para la familia (opcional)'],
        'type' => 'text',
        'optional' => 'true',
        'text' => ['maximum_length' => 255],
    ]],
    'success_url' => site_url('gracias.php') . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => site_url('index.php') . '?cancelado=1#donar',
];
if (cfg('statement_descriptor_suffix')) {
    $params['payment_intent_data']['statement_descriptor_suffix'] = substr((string) cfg('statement_descriptor_suffix'), 0, 22);
}

try {
    $session = stripe_request('POST', 'checkout/sessions', $params);
} catch (Throwable $ex) {
    error_log('memorial checkout: ' . $ex->getMessage());
    back('No pudimos iniciar el pago. Por favor, inténtalo de nuevo en unos minutos.');
}
header('Location: ' . $session['url'], true, 303);
