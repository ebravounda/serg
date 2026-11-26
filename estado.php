<?php
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$p = progress();
echo json_encode($p + [
    'raised_text' => money($p['raised']),
    'goal_text' => money($p['goal']),
]);
