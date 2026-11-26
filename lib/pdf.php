<?php
declare(strict_types=1);

/**
 * Generador de PDF mínimo (sin librerías): páginas A4, Helvetica, texto,
 * líneas y rectángulos. Suficiente para informes con tablas.
 */
final class SimplePdf
{
    public const W = 595.28;
    public const H = 841.89;
    private array $pages = [];
    private string $cur = '';
    private array $widths;

    public function __construct()
    {
        // Anchos de Helvetica (unidades /1000) para ASCII 32–126
        $w = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
        $this->widths = [];
        foreach ($w as $i => $v) {
            $this->widths[chr(32 + $i)] = $v;
        }
    }

    public function addPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = ' ';
    }

    /** Convierte UTF-8 a la codificación de las fuentes PDF estándar (WinAnsi). */
    private function enc(string $s): string
    {
        $s = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $raw = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        $t = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            $o = ord($c);
            $t += $this->widths[$c] ?? ($o >= 0xC0 && $o <= 0xDE ? 667 : ($o === 0xED || $o === 0xEC ? 278 : 556));
        }
        return $t * $size / 1000 * ($bold ? 1.06 : 1);
    }

    /** Recorta el texto con «…» para que quepa en $max puntos. */
    public function fit(string $s, float $max, float $size, bool $bold = false): string
    {
        if ($this->textWidth($s, $size, $bold) <= $max) {
            return $s;
        }
        while ($s !== '' && $this->textWidth($s . '…', $size, $bold) > $max) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '…';
    }

    /** Texto con origen arriba-izquierda. $align: L, R o C (respecto a $x). */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'L', array $rgb = [0.15, 0.14, 0.13]): void
    {
        $w = $this->textWidth($s, $size, $bold);
        if ($align === 'R') {
            $x -= $w;
        } elseif ($align === 'C') {
            $x -= $w / 2;
        }
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, self::H - $y - $size * 0.8, $this->enc($s));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [0.85, 0.83, 0.8], float $w = 0.6): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $w, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    public function output(string $title = ''): string
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
            $this->cur = '';
        }
        $objs = [];
        $n = count($this->pages);
        // 1 catálogo, 2 páginas, 3-4 fuentes, 5 info, luego (página, contenido) por cada página
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (6 + $i * 2) . ' 0 R';
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Title (' . $this->enc($title) . ') /Producer (Memorial) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        foreach ($this->pages as $i => $content) {
            $p = 6 + $i * 2;
            $data = function_exists('gzcompress') ? gzcompress($content) : $content;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objs[$p] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $p + 1);
            $objs[$p + 1] = '<< /Length ' . strlen($data) . $filter . " >>\nstream\n" . $data . "\nendstream";
        }
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}

/**
 * Informe de la colecta en PDF, para compartir con quien lo pida.
 * $withNames: incluir nombres de los donantes (nunca se incluyen correos).
 */
function build_report_pdf(bool $withNames): string
{
    $s = compute_stats();
    $set = settings();
    $list = array_reverse(donations()); // orden cronológico
    $pdf = new SimplePdf();
    $M = 42.0;                       // margen
    $R = SimplePdf::W - $M;          // borde derecho
    $ink = [0.15, 0.14, 0.13];
    $soft = [0.45, 0.42, 0.39];
    $accent = [0.23, 0.22, 0.2];
    $pageNo = 0;
    $generated = date_es(time(), true);

    $header = function (string $subtitle) use ($pdf, $M, $R, $soft, $set, &$pageNo, $generated) {
        $pdf->addPage();
        $pageNo++;
        $pdf->text($M, 34, 'EN MEMORIA DE ' . mb_strtoupper($set['name']), 8, false, 'L', $soft);
        $pdf->text($R, 34, 'Informe generado el ' . $generated, 8, false, 'R', $soft);
        $pdf->line($M, 50, $R, 50);
        $pdf->text($M, 62, $subtitle, 15, true);
        return 92.0;
    };
    $footer = function () use ($pdf, $M, $R, $soft, &$pageNo) {
        $pdf->line($M, SimplePdf::H - 40, $R, SimplePdf::H - 40);
        $pdf->text($M, SimplePdf::H - 34, 'Datos obtenidos de Stripe. Importes en euros. Horas en hora peninsular española.', 7.5, false, 'L', $soft);
        $pdf->text($R, SimplePdf::H - 34, 'Página ' . $pageNo, 7.5, false, 'R', $soft);
    };

    /* ---------- Página 1: resumen ---------- */
    $y = $header('Informe de la colecta');
    $pdf->text($M, $y, 'Colecta para cubrir los gastos del servicio funerario de ' . $set['name'] . '.', 10, false, 'L', $soft);
    $y += 16;
    if ($s['first']) {
        $pdf->text($M, $y, 'Periodo: del ' . date_es((int) $s['first']) . ' al ' . date_es((int) $s['last']) . '.', 10, false, 'L', $soft);
    }
    $y += 30;

    // Barra de progreso (neto)
    $pdf->text($M, $y, 'Recaudado (neto, lo que llega a la cuenta)', 9, false, 'L', $soft);
    $pdf->text($R, $y, 'Meta: ' . money($s['goal']), 9, false, 'R', $soft);
    $y += 14;
    $pdf->text($M, $y, money($s['net']), 22, true);
    $pdf->text($R, $y + 6, pct(min(100, $s['goal_pct_net'])) . ' de la meta', 11, false, 'R', $ink);
    $y += 32;
    $bw = $R - $M;
    $pdf->rect($M, $y, $bw, 8, [0.91, 0.89, 0.85]);
    $pdf->rect($M, $y, $bw * min(1, $s['goal_pct_net'] / 100), 8, $accent);
    $y += 30;

    $rows = [
        ['Donaciones recibidas', (string) $s['count']],
        ['Personas distintas (por correo)', (string) $s['donors']],
        ['Total donado (bruto)', money($s['gross'])],
        ['Reembolsos', $s['refunded'] ? '- ' . money($s['refunded']) : money(0)],
        ['Comisiones de Stripe', '- ' . money($s['fees']) . '  (' . pct($s['fee_pct']) . ')'],
        ['Neto que llega a la cuenta', money($s['net'])],
        ['Falta para la meta (neto)', money($s['remaining'])],
        ['Donación media', money($s['avg'])],
        ['Donación mediana', money($s['median'])],
        ['Donación mayor / menor', money($s['max']) . ' / ' . money($s['min'])],
    ];
    foreach ($rows as $i => [$k, $v]) {
        $bold = $k === 'Neto que llega a la cuenta';
        if ($i % 2 === 0) {
            $pdf->rect($M, $y - 4, $bw, 20, [0.965, 0.955, 0.94]);
        }
        $pdf->text($M + 8, $y, $k, 10, $bold);
        $pdf->text($R - 8, $y, $v, 10, $bold, 'R');
        $y += 20;
    }
    if ($s['fee_pending']) {
        $y += 6;
        $pdf->text($M, $y, $s['fee_pending'] . ' donación(es) recientes aún sin comisión confirmada por Stripe: se cuenta su importe bruto.', 8, false, 'L', $soft);
    }

    // Por país
    $y += 32;
    $pdf->text($M, $y, 'Por país', 12, true);
    $y += 22;
    $cols = [$M + 8, $R - 230, $R - 120, $R - 8];
    $pdf->text($cols[0], $y, 'País', 8.5, true, 'L', $soft);
    $pdf->text($cols[1], $y, 'Donaciones', 8.5, true, 'R', $soft);
    $pdf->text($cols[2], $y, 'Donado', 8.5, true, 'R', $soft);
    $pdf->text($cols[3], $y, 'Neto', 8.5, true, 'R', $soft);
    $y += 14;
    foreach (array_slice($s['by_country'], 0, 12, true) as $code => $c) {
        $pdf->line($M, $y - 3, $R, $y - 3);
        $pdf->text($cols[0], $y + 1, country_name((string) $code), 9.5);
        $pdf->text($cols[1], $y + 1, (string) $c['count'], 9.5, false, 'R');
        $pdf->text($cols[2], $y + 1, money($c['amount']), 9.5, false, 'R');
        $pdf->text($cols[3], $y + 1, money($c['net']), 9.5, false, 'R');
        $y += 17;
    }
    $footer();

    /* ---------- Páginas siguientes: cada transacción ---------- */
    // Columnas: posiciones x (los importes se alinean a la derecha)
    $cols = $withNames
        ? ['n' => $M, 'f' => $M + 18, 'h' => $M + 68, 'p' => $M + 98, 'm' => $M + 170, 'who' => $M + 256, 'b' => $R - 100, 'c' => $R - 50, 'net' => $R]
        : ['n' => $M, 'f' => $M + 22, 'h' => $M + 90, 'p' => $M + 130, 'm' => $M + 240, 'b' => $R - 108, 'c' => $R - 54, 'net' => $R];
    $textEnd = $cols['b'] - 42; // donde acaba el texto antes de la columna «Donado»
    $tableHead = function () use ($pdf, $cols, $soft, $withNames, $M, $R, $textEnd) {
        $y = 92.0;
        $pdf->text($cols['n'], $y, '#', 8, true, 'L', $soft);
        $pdf->text($cols['f'], $y, 'Fecha', 8, true, 'L', $soft);
        $pdf->text($cols['h'], $y, 'Hora', 8, true, 'L', $soft);
        $pdf->text($cols['p'], $y, 'País', 8, true, 'L', $soft);
        $pdf->text($cols['m'], $y, 'Método de pago', 8, true, 'L', $soft);
        if ($withNames) {
            $pdf->text($cols['who'], $y, 'Nombre', 8, true, 'L', $soft);
        }
        $pdf->text($cols['b'], $y, 'Donado', 8, true, 'R', $soft);
        $pdf->text($cols['c'], $y, 'Comisión', 8, true, 'R', $soft);
        $pdf->text($cols['net'], $y, 'Neto', 8, true, 'R', $soft);
        $pdf->line($M, $y + 13, $R, $y + 13, [0.6, 0.58, 0.55], 0.8);
        return $y + 20;
    };
    $y = SimplePdf::H; // fuerza página nueva
    $i = 0;
    foreach ($list as $d) {
        if ($y > SimplePdf::H - 70) {
            if ($i > 0) {
                $footer();
            }
            $header('Detalle de las donaciones');
            $y = $tableHead();
        }
        $i++;
        $t = local_time((int) $d['created']);
        $refunded = (int) ($d['refunded'] ?? 0);
        $fs = 8.5;
        $grey = $d['amount'] <= 0 ? $soft : $ink;
        $pdf->text($cols['n'], $y, (string) $i, $fs, false, 'L', $soft);
        $pdf->text($cols['f'], $y, $t->format('d/m/Y'), $fs, false, 'L', $grey);
        $pdf->text($cols['h'], $y, $t->format('H:i'), $fs, false, 'L', $grey);
        $pdf->text($cols['p'], $y, $pdf->fit(country_name(donation_country($d)), $cols['m'] - $cols['p'] - 6, $fs), $fs, false, 'L', $grey);
        $nextCol = $withNames ? $cols['who'] : $textEnd;
        $pdf->text($cols['m'], $y, $pdf->fit(method_label($d), $nextCol - $cols['m'] - 6, $fs), $fs, false, 'L', $grey);
        if ($withNames) {
            $pdf->text($cols['who'], $y, $pdf->fit(public_donor_name($d), $textEnd - $cols['who'], $fs), $fs, false, 'L', $grey);
        }
        $pdf->text($cols['b'], $y, money((int) ($d['gross'] ?? $d['amount'])), $fs, false, 'R', $grey);
        $pdf->text($cols['c'], $y, isset($d['fee']) ? money((int) $d['fee']) : '—', $fs, false, 'R', $grey);
        $pdf->text($cols['net'], $y, money(donation_net($d)), $fs, true, 'R', $grey);
        if ($refunded > 0) {
            $y += 11;
            $pdf->text($cols['f'], $y, 'Reembolsado: ' . money($refunded), 7.5, false, 'L', $soft);
        }
        $pdf->line($M, $y + 12, $R, $y + 12, [0.9, 0.88, 0.85], 0.4);
        $y += 17;
    }
    if ($i > 0) {
        $y += 6;
        $pdf->text($cols['m'], $y, 'Total', 9.5, true);
        $pdf->text($cols['b'], $y, money($s['gross']), 9.5, true, 'R');
        $pdf->text($cols['c'], $y, money($s['fees']), 9.5, true, 'R');
        $pdf->text($cols['net'], $y, money($s['net']), 9.5, true, 'R');
        $footer();
    }
    return $pdf->output('Informe de la colecta - En memoria de ' . $set['name']);
}
