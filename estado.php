<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/stats.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$p = progress();
$last = last_donation();
$st = compute_stats();
echo json_encode($p + [
    'donated_text' => money($st['donated']),
    'fees_text' => money($st['fees']),
    'fee_pct_text' => pct($st['fee_pct']) . ' de lo donado',
    'net_text' => money($st['net']),
    'last_name' => $last['name'] ?? null,
    'last_ago' => $last['ago'] ?? null,
    'raised_text' => money($p['raised']),
    'goal_text' => money($p['goal']),
]);
