<?php
/**
 * Webhook de Stripe. Eventos a suscribir:
 *   checkout.session.completed, checkout.session.async_payment_succeeded, charge.refunded
 */
require __DIR__ . '/lib/bootstrap.php';

$payload = (string) file_get_contents('php://input');
$secret = (string) cfg('stripe_webhook_secret');
$header = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

function verify_signature(string $payload, string $header, string $secret): bool
{
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $t = (int) $v;
        } elseif ($k === 'v1') {
            $sigs[] = $v;
        }
    }
    if (!$t || !$sigs || abs(time() - $t) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

if ($secret === '' || !verify_signature($payload, $header, $secret)) {
    http_response_code(400);
    exit('firma no válida');
}

$event = json_decode($payload, true);
$obj = $event['data']['object'] ?? [];
try {
    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            if (($obj['metadata']['campaign'] ?? null) === cfg('campaign_id')) {
                record_checkout_session((string) $obj['id']);
            }
            break;
        case 'charge.refunded':
            if (!empty($obj['payment_intent'])) {
                $pi = stripe_request('GET', 'payment_intents/' . rawurlencode($obj['payment_intent']), [
                    'expand' => ['latest_charge'],
                ]);
                record_payment_intent($pi);
            }
            break;
    }
} catch (Throwable $ex) {
    error_log('memorial webhook: ' . $ex->getMessage());
    http_response_code(500); // Stripe reintentará
    exit('error');
}
http_response_code(200);
echo 'ok';
