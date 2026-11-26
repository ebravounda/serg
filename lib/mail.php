<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Correo con Resend (https://resend.com)
 * ------------------------------------------------------------------ */

function mail_ready(): bool
{
    return (string) cfg('resend_api_key') !== '' && (string) cfg('mail_from_email') !== '';
}

function default_mail_templates(): array
{
    return [
        'thanks_enabled' => true,
        'thanks_subject' => 'Gracias por tu aporte en memoria de Sergio',
        'thanks_body' => "Hola {nombre},\n\nQueremos darte las gracias de corazón por tu aportación de {importe} para despedir a Sergio como se merece.\n\nEntre todos ya hemos reunido {recaudado} de los {meta} necesarios para cubrir los gastos del servicio funerario. Iremos contando cada avance en la página:\n{enlace}\n\nCon cariño,\nLos amigos de Sergio",
    ];
}

function mail_templates(): array
{
    return store_read('mail_templates', []) + default_mail_templates();
}

/** Sustituye {nombre}, {importe}, {fecha}, {recaudado}, {meta}, {enlace}. */
function fill_placeholders(string $text, array $donation = []): string
{
    $p = progress();
    $first = trim(explode(' ', trim((string) ($donation['name'] ?? '')))[0] ?? '');
    $first = $first !== '' ? mb_convert_case(mb_strtolower($first), MB_CASE_TITLE) : '';
    $text = strtr($text, [
        '{nombre}' => $first,
        '{importe}' => isset($donation['amount']) ? money((int) $donation['amount']) : '',
        '{fecha}' => isset($donation['created']) ? date_es((int) $donation['created']) : date_es(time()),
        '{recaudado}' => money($p['raised']),
        '{meta}' => money($p['goal']),
        '{enlace}' => site_url(''),
    ]);
    // "Hola ," cuando no hay nombre → "Hola,"
    return preg_replace('/[ \t]+([,.;:])/u', '$1', $text);
}

/** Envoltorio HTML sobrio para todos los correos. */
function email_html(string $bodyText): string
{
    $s = settings();
    $photo = photo_url($s);
    $img = $photo !== ''
        ? '<img src="' . e(site_url($photo)) . '" width="96" height="96" alt="" style="display:block;margin:0 auto 18px;border-radius:50%;width:96px;height:96px;object-fit:cover">'
        : '';
    $paras = '';
    foreach (preg_split("/\R{2,}/", trim($bodyText)) as $p) {
        if (trim($p) === '') {
            continue;
        }
        $html = nl2br(e(trim($p)));
        // enlaces clicables
        $html = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#3b3833">$1</a>', $html);
        $paras .= '<p style="margin:0 0 16px">' . $html . '</p>';
    }
    return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#f5f3ef">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef"><tr><td align="center" style="padding:32px 16px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fbfaf8;border:1px solid #ddd7ce;border-radius:6px">'
        . '<tr><td style="padding:32px 32px 8px;text-align:center;font-family:Georgia,\'Times New Roman\',serif;color:#262422">'
        . $img
        . '<div style="font:12px Arial,sans-serif;letter-spacing:3px;text-transform:uppercase;color:#8a847c">En memoria de</div>'
        . '<div style="font-size:26px;margin:6px 0 4px">' . e($s['name']) . '</div>'
        . '<div style="width:48px;height:1px;background:#8a847c;margin:16px auto 0"></div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 32px 16px;font:16px/1.65 Arial,Helvetica,sans-serif;color:#34312e">' . $paras . '</td></tr>'
        . '</table>'
        . '<p style="font:12px Arial,sans-serif;color:#8a847c;margin:16px 0 0">' . e($s['organizer']) . '</p>'
        . '</td></tr></table></body></html>';
}

function mail_from(): string
{
    $name = trim((string) cfg('mail_from_name'));
    $email = trim((string) cfg('mail_from_email'));
    return $name !== '' ? sprintf('%s <%s>', str_replace(['<', '>', '"'], '', $name), $email) : $email;
}

function resend_request(string $path, array $payload, ?string $idempotencyKey = null): array
{
    $key = (string) cfg('resend_api_key');
    if ($key === '') {
        throw new RuntimeException('Resend no está configurado (falta la API key en Conexiones).');
    }
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    $ch = curl_init(rtrim((string) cfg('resend_api_base', 'https://api.resend.com'), '/') . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('No se pudo conectar con Resend: ' . $err);
    }
    $json = json_decode((string) $body, true);
    if ($status >= 400) {
        throw new RuntimeException('Resend: ' . ($json['message'] ?? "error HTTP $status"));
    }
    return is_array($json) ? $json : [];
}

function mail_log(string $type, string $to, string $subject, string $status, string $detail = ''): void
{
    store_update('mail_log', function ($log) use ($type, $to, $subject, $status, $detail) {
        array_unshift($log, ['at' => time(), 'type' => $type, 'to' => $to, 'subject' => $subject, 'status' => $status, 'detail' => $detail]);
        return array_slice($log, 0, 500);
    });
}

/** Envía un correo. Lanza excepción si falla (y lo deja en el registro). */
function send_mail(string $type, string $to, string $subject, string $text, ?string $idempotencyKey = null): string
{
    if (!mail_ready()) {
        throw new RuntimeException('El correo no está configurado: completa la API key de Resend y el remitente en Conexiones.');
    }
    $payload = [
        'from' => mail_from(),
        'to' => [$to],
        'subject' => $subject,
        'html' => email_html($text),
        'text' => $text,
    ];
    if ((string) cfg('mail_reply_to') !== '') {
        $payload['reply_to'] = (string) cfg('mail_reply_to');
    }
    try {
        $res = resend_request('emails', $payload, $idempotencyKey);
    } catch (Throwable $ex) {
        mail_log($type, $to, $subject, 'error', $ex->getMessage());
        throw $ex;
    }
    mail_log($type, $to, $subject, 'enviado', (string) ($res['id'] ?? ''));
    return (string) ($res['id'] ?? '');
}

/** Envía el correo de agradecimiento de una donación. */
function send_thanks(string $donationId, bool $force = false): void
{
    $d = store_read('donations', [])[$donationId] ?? null;
    if (!$d || trim((string) ($d['email'] ?? '')) === '') {
        throw new RuntimeException('Esta donación no tiene correo electrónico.');
    }
    $t = mail_templates();
    try {
        send_mail(
            'agradecimiento',
            $d['email'],
            fill_placeholders($t['thanks_subject'], $d),
            fill_placeholders($t['thanks_body'], $d),
            $force ? null : 'gracias-' . $donationId
        );
        $status = ['status' => 'enviado', 'at' => time()];
    } catch (Throwable $ex) {
        $status = ['status' => 'error', 'at' => time(), 'error' => $ex->getMessage()];
    }
    store_update('donations', function ($all) use ($donationId, $status) {
        if (isset($all[$donationId])) {
            $all[$donationId]['thanks'] = $status;
        }
        return $all;
    });
    if ($status['status'] === 'error') {
        throw new RuntimeException($status['error']);
    }
}

/**
 * Agradecimiento automático: una sola vez por donación, y solo para donaciones
 * recientes (así, al configurar Resend tarde, no se escribe a gente de hace semanas).
 */
function maybe_send_thanks(string $donationId): void
{
    if (!mail_ready() || !mail_templates()['thanks_enabled']) {
        return;
    }
    $claimed = false;
    store_update('donations', function ($all) use ($donationId, &$claimed) {
        $d = $all[$donationId] ?? null;
        if ($d && empty($d['thanks']) && trim((string) ($d['email'] ?? '')) !== ''
            && (int) $d['created'] > time() - 3 * 86400) {
            $all[$donationId]['thanks'] = ['status' => 'enviando', 'at' => time()];
            $claimed = true;
        }
        return $all;
    });
    if ($claimed) {
        try {
            send_thanks($donationId);
        } catch (Throwable $ex) {
            error_log('memorial gracias email: ' . $ex->getMessage());
        }
    }
}

/** Aviso al organizador de cada donación nueva (si hay correo de aviso). */
function notify_new_donation(string $donationId): void
{
    $to = trim((string) cfg('notify_email'));
    if ($to === '' || !mail_ready()) {
        return;
    }
    $d = store_read('donations', [])[$donationId] ?? null;
    if (!$d) {
        return;
    }
    $p = progress();
    $text = "Nueva donación de " . money((int) $d['amount'])
        . ($d['name'] ? ' de ' . $d['name'] . (!empty($d['anonymous']) ? ' (pidió aparecer como anónimo)' : '') : '') . ($d['email'] ? ' (' . $d['email'] . ')' : '') . ".\n\n"
        . (trim($d['message'] ?? '') !== '' ? "Mensaje: «" . $d['message'] . "»\n\n" : '')
        . "Recaudado: " . money($p['raised']) . ' de ' . money($p['goal']) . " ({$p['percent']}%).\n\n"
        . site_url('admin/');
    try {
        send_mail('aviso', $to, 'Nueva donación: ' . money((int) $d['amount']), $text, 'aviso-' . $donationId);
    } catch (Throwable $ex) {
        error_log('memorial aviso email: ' . $ex->getMessage());
    }
}

/** Envía un mensaje a todos los donantes con correo (sin repetir direcciones). */
function broadcast(string $subject, string $body): array
{
    $sent = 0;
    $failed = 0;
    $seen = [];
    foreach (donations() as $d) {
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($email === '' || $d['amount'] <= 0 || isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        try {
            send_mail('mensaje', $d['email'], fill_placeholders($subject, $d), fill_placeholders($body, $d));
            $sent++;
        } catch (Throwable $ex) {
            $failed++;
        }
        usleep(250000); // Resend permite ~5 envíos por segundo
    }
    return [$sent, $failed];
}

function broadcast_recipients(): int
{
    $seen = [];
    foreach (donations() as $d) {
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if ($email !== '' && $d['amount'] > 0) {
            $seen[$email] = true;
        }
    }
    return count($seen);
}
