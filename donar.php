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
    'metadata' => ['campaign' => $campaign],
    'payment_intent_data' => [
        'description' => $label,
        'metadata' => ['campaign' => $campaign],
    ],
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
