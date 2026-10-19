<?php
/**
 * Restituisce la top 5 dei risultati salvati.
 * Restituisce due classifiche separate (adattiva e manuale).
 */

define('ENTRY_POINT', true);
require_once __DIR__ . '/../.config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$file = __DIR__ . '/../data/leaderboard.json';
if (!file_exists($file)) {
    echo json_encode(['adattiva' => ['top5' => [], 'total' => 0], 'manuale' => ['top5' => [], 'total' => 0]]);
    exit;
}

$contents = @file_get_contents($file);
$list = json_decode($contents, true);
if (!is_array($list)) {
    echo json_encode(['adattiva' => ['top5' => [], 'total' => 0], 'manuale' => ['top5' => [], 'total' => 0]]);
    exit;
}

// Calcolo punteggio classifica composito.
// Bonus: aperto = 1.0, scelta multipla = 0.8 (default per retro-compat sui record senza il campo)
$computeLb = function (&$e) {
    $score      = isset($e['score']) ? (int)$e['score'] : 0;
    $avgDiff    = isset($e['avgDiff']) ? (int)$e['avgDiff'] : 0;
    $spec       = isset($e['spec']) ? (int)$e['spec'] : 50;
    $aType      = isset($e['answerType']) ? $e['answerType'] : 'multiple';
    $bonus      = ($aType === 'open') ? 1.0 : 0.8;
    $multiplier = 0.5 + ($avgDiff / 200.0) + ($spec / 200.0);
    $lb = ($score * $multiplier * $bonus) / 1.5;
    $e['lbScore'] = (int)round(max(0, min(100, $lb)));
};

foreach ($list as &$e) { $computeLb($e); }
unset($e);

$cmp = function ($a, $b) {
    $la = isset($a['lbScore']) ? $a['lbScore'] : 0;
    $lb = isset($b['lbScore']) ? $b['lbScore'] : 0;
    if ($la !== $lb) return $lb - $la;
    return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
};

$adattiva = array_values(array_filter($list, function ($e) { return (isset($e['mode']) ? $e['mode'] : '') === 'adattiva'; }));
$manuale  = array_values(array_filter($list, function ($e) { return (isset($e['mode']) ? $e['mode'] : '') === 'manuale'; }));
usort($adattiva, $cmp);
usort($manuale, $cmp);

echo json_encode([
    'adattiva' => ['top5' => array_slice($adattiva, 0, 5), 'total' => count($adattiva)],
    'manuale'  => ['top5' => array_slice($manuale, 0, 5),  'total' => count($manuale)],
], JSON_UNESCAPED_UNICODE);
