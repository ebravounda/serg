<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Estadísticas de la colecta (solo donaciones hechas con el botón de la web,
 * en el modo actual de Stripe: prueba o real).
 * ------------------------------------------------------------------ */

function country_name(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return 'Desconocido';
    }
    if (class_exists('Locale')) {
        $n = Locale::getDisplayRegion('-' . $code, 'es');
        if ($n !== '' && $n !== $code) {
            return $n;
        }
    }
    static $map = [
        'ES' => 'España', 'AR' => 'Argentina', 'CL' => 'Chile', 'UY' => 'Uruguay', 'MX' => 'México', 'CO' => 'Colombia',
        'PE' => 'Perú', 'VE' => 'Venezuela', 'EC' => 'Ecuador', 'BO' => 'Bolivia', 'PY' => 'Paraguay', 'US' => 'Estados Unidos',
        'GB' => 'Reino Unido', 'FR' => 'Francia', 'DE' => 'Alemania', 'IT' => 'Italia', 'PT' => 'Portugal', 'NL' => 'Países Bajos',
        'BE' => 'Bélgica', 'CH' => 'Suiza', 'IE' => 'Irlanda', 'AT' => 'Austria', 'SE' => 'Suecia', 'NO' => 'Noruega',
        'DK' => 'Dinamarca', 'FI' => 'Finlandia', 'PL' => 'Polonia', 'BR' => 'Brasil', 'CA' => 'Canadá', 'AD' => 'Andorra',
        'LU' => 'Luxemburgo', 'RO' => 'Rumanía', 'CU' => 'Cuba', 'DO' => 'República Dominicana', 'CR' => 'Costa Rica',
        'PA' => 'Panamá', 'GT' => 'Guatemala', 'AU' => 'Australia', 'MA' => 'Marruecos',
    ];
    return $map[$code] ?? $code;
}

/** País de la donación: el de facturación; si no, el del banco emisor de la tarjeta. */
function donation_country(array $d): string
{
    return strtoupper((string) (($d['country'] ?? '') ?: ($d['card_country'] ?? '')));
}

function method_label(array $d): string
{
    $wallets = ['apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay', 'samsung_pay' => 'Samsung Pay', 'link' => 'Link'];
    $brands = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover',
        'diners' => 'Diners', 'jcb' => 'JCB', 'unionpay' => 'UnionPay', 'cartes_bancaires' => 'Cartes Bancaires'];
    $types = ['card' => 'Tarjeta', 'sepa_debit' => 'Débito SEPA', 'paypal' => 'PayPal', 'bancontact' => 'Bancontact',
        'ideal' => 'iDEAL', 'link' => 'Link', 'klarna' => 'Klarna', 'revolut_pay' => 'Revolut Pay'];
    $wallet = (string) ($d['wallet'] ?? '');
    $brand = (string) ($d['brand'] ?? '');
    if ($wallet !== '') {
        return ($wallets[$wallet] ?? ucfirst(str_replace('_', ' ', $wallet))) . ($brand ? ' (' . ($brands[$brand] ?? ucfirst($brand)) . ')' : '');
    }
    if ($brand !== '') {
        return $brands[$brand] ?? ucfirst($brand);
    }
    $t = (string) ($d['method'] ?? '');
    return $t !== '' ? ($types[$t] ?? ucfirst(str_replace('_', ' ', $t))) : '—';
}

function method_detail(array $d): string
{
    $label = method_label($d);
    return ($d['last4'] ?? '') !== '' ? $label . ' ····' . $d['last4'] : $label;
}

function local_time(int $ts): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Madrid'));
}

function fee_type_label(string $type): string
{
    return ['stripe_fee' => 'Comisión de Stripe', 'tax' => 'Impuestos sobre la comisión', 'application_fee' => 'Comisión de plataforma'][$type]
        ?? ucfirst(str_replace('_', ' ', $type));
}

function compute_stats(): array
{
    $all = donations();
    $valid = array_values(array_filter($all, fn($d) => (int) $d['amount'] > 0));
    $s = [
        'transactions' => count($all),
        'count' => count($valid),
        'refunded_count' => count(array_filter($all, fn($d) => (int) ($d['refunded'] ?? 0) > 0)),
        'gross' => 0, 'refunded' => 0, 'donated' => 0, 'fees' => 0, 'net' => 0,
        'net_available' => 0, 'net_pending' => 0, 'fee_pending' => 0,
        'fee_types' => [], 'by_day' => [], 'by_country' => [], 'by_method' => [], 'by_hour' => array_fill(0, 24, 0),
        'avg' => 0, 'median' => 0, 'max' => 0, 'min' => 0, 'donors' => 0,
        'first' => null, 'last' => null,
    ];
    $emails = [];
    $amounts = [];
    $now = time();
    foreach ($all as $d) {
        $gross = (int) ($d['gross'] ?? $d['amount']);
        $s['gross'] += $gross;
        $s['refunded'] += (int) ($d['refunded'] ?? 0);
        $s['fees'] += (int) ($d['fee'] ?? 0);
        $net = donation_net($d);
        $s['net'] += $net;
        if (!empty($d['fee_pending']) || !isset($d['fee'])) {
            $s['fee_pending']++;
        }
        if (!empty($d['available_on']) && (int) $d['available_on'] > $now) {
            $s['net_pending'] += $net;
        } else {
            $s['net_available'] += $net;
        }
        foreach ($d['fee_details'] ?? [] as $f) {
            $s['fee_types'][$f['type']] = ($s['fee_types'][$f['type']] ?? 0) + (int) $f['amount'];
        }
    }
    $s['donated'] = $s['gross'] - $s['refunded'];

    foreach ($valid as $d) {
        $amt = (int) $d['amount'];
        $net = donation_net($d);
        $amounts[] = $amt;
        $t = local_time((int) $d['created']);
        $day = $t->format('Y-m-d');
        $s['by_day'][$day] ??= ['count' => 0, 'amount' => 0, 'net' => 0];
        $s['by_day'][$day]['count']++;
        $s['by_day'][$day]['amount'] += $amt;
        $s['by_day'][$day]['net'] += $net;

        $c = donation_country($d);
        $s['by_country'][$c] ??= ['count' => 0, 'amount' => 0, 'net' => 0];
        $s['by_country'][$c]['count']++;
        $s['by_country'][$c]['amount'] += $amt;
        $s['by_country'][$c]['net'] += $net;

        $m = method_label($d);
        $s['by_method'][$m] ??= ['count' => 0, 'amount' => 0, 'net' => 0];
        $s['by_method'][$m]['count']++;
        $s['by_method'][$m]['amount'] += $amt;
        $s['by_method'][$m]['net'] += $net;

        $s['by_hour'][(int) $t->format('G')]++;
        if (($d['email'] ?? '') !== '') {
            $emails[strtolower($d['email'])] = true;
        }
        $s['first'] = $s['first'] === null ? (int) $d['created'] : min($s['first'], (int) $d['created']);
        $s['last'] = max((int) $s['last'], (int) $d['created']);
    }
    ksort($s['by_day']);
    uasort($s['by_country'], fn($a, $b) => $b['amount'] <=> $a['amount']);
    uasort($s['by_method'], fn($a, $b) => $b['amount'] <=> $a['amount']);
    if ($amounts) {
        sort($amounts);
        $n = count($amounts);
        $s['avg'] = (int) round(array_sum($amounts) / $n);
        $s['median'] = $n % 2 ? $amounts[intdiv($n, 2)] : (int) round(($amounts[$n / 2 - 1] + $amounts[$n / 2]) / 2);
        $s['max'] = $amounts[$n - 1];
        $s['min'] = $amounts[0];
    }
    $s['donors'] = count($emails);
    $s['fee_pct'] = $s['gross'] > 0 ? $s['fees'] * 100 / $s['gross'] : 0;
    $goal = max(1, (int) settings()['goal_cents']);
    $s['goal'] = $goal;
    $s['goal_pct_net'] = $s['net'] * 100 / $goal;
    $s['remaining'] = max(0, $goal - $s['net']);
    return $s;
}

/** Saldo real de la cuenta de Stripe (si la cuenta es exclusiva de la colecta, es el dinero de la colecta). */
function stripe_balance(): ?array
{
    if (!stripe_ready()) {
        return null;
    }
    try {
        $b = stripe_request('GET', 'balance');
    } catch (Throwable $ex) {
        return null;
    }
    $sum = fn($list) => array_sum(array_map(fn($x) => ($x['currency'] ?? '') === cfg('currency') ? (int) $x['amount'] : 0, $list ?? []));
    return ['available' => $sum($b['available'] ?? []), 'pending' => $sum($b['pending'] ?? [])];
}

function pct(float $v): string
{
    return number_format($v, 1, ',', '.') . ' %';
}
