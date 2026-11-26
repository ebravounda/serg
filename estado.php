<?php
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$p = progress();
$last = last_donation();
echo json_encode($p + [
    'last_name' => $last['name'] ?? null,
    'last_ago' => $last['ago'] ?? null,
    'raised_text' => money($p['raised']),
    'goal_text' => money($p['goal']),
]);
