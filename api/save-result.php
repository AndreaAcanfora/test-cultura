<?php
/**
 * Salva un risultato del quiz nello storico (data/leaderboard.json).
 *
 * Dal browser arriva solo il nome: punteggio, difficoltà e configurazione si
 * calcolano dal quiz in sessione, con i punteggi assegnati da grade-batch.
 */

define('ENTRY_POINT', true);
require_once __DIR__ . '/../.config.php';
require_once __DIR__ . '/_run.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Validazione e sanificazione
$nome = isset($data['nome']) ? trim((string)$data['nome']) : '';
$nome = preg_replace('/\s+/u', ' ', $nome);
$nome = mb_substr($nome, 0, 30, 'UTF-8');
if ($nome === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nome obbligatorio']);
    exit;
}

$run = run_get();
if ($run === null) json_error(409, 'Nessun quiz da salvare.');
if ($run['saved']) json_error(409, 'Risultato già salvato.');
$served = $run['served'];
if (count($served) === 0 || (count($served) < $run['cfg']['qcount'] && !$run['exhausted'])) {
    json_error(409, 'Quiz non completato.');
}
$weighted = 0;
$totalWeight = 0;
foreach ($served as $id => $diff) {
    if (!isset($run['graded'][$id])) json_error(409, 'Quiz non completato.');
    $weighted += $run['graded'][$id]['score'] * $diff;
    $totalWeight += $diff;
}
$score = $totalWeight > 0 ? (int)round($weighted / $totalWeight) : 0;
$avgDiff = (int)round($totalWeight / count($served));
$mode = $run['cfg']['mode'];
$qcount = count($served);
$spec = $run['cfg']['spec'];
$levelReached = $mode === 'adattiva' ? $run['maxLevel'] : '';
$answerType = $run['cfg']['answerType'];

$entry = [
    'nome'       => $nome,
    'score'      => $score,
    'mode'       => $mode,
    'qcount'     => $qcount,
    'spec'       => $spec,
    'avgDiff'    => $avgDiff,
    'level'      => $levelReached,
    'answerType' => $answerType,
    'timestamp'  => time(),
];

// Lettura/scrittura con lock
$file = __DIR__ . '/../data/leaderboard.json';
$dir = dirname($file);
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$fp = fopen($file, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot open storage']);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Lock failed']);
    exit;
}

$contents = stream_get_contents($fp);
$list = json_decode($contents, true);
if (!is_array($list)) $list = [];

$list[] = $entry;

// Mantieni solo le ultime 500 voci per non far crescere il file all'infinito
if (count($list) > 500) {
    $list = array_slice($list, -500);
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

$run['saved'] = true;
run_put($run);

// Calcola lbScore per ogni record e posizione in classifica.
// Bonus: aperto = 1.0, scelta multipla = 0.8 (anche per record vecchi senza answerType)
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
$computeLb($entry);

$cmp = function ($a, $b) {
    $la = isset($a['lbScore']) ? $a['lbScore'] : 0;
    $lb = isset($b['lbScore']) ? $b['lbScore'] : 0;
    if ($la !== $lb) return $lb - $la;
    return $a['timestamp'] - $b['timestamp']; // a parità, prima il più vecchio
};
$modeFilter = $mode;
$sameMode = array_values(array_filter($list, function ($e) use ($modeFilter) { return (isset($e['mode']) ? $e['mode'] : '') === $modeFilter; }));
usort($sameMode, $cmp);

$position = null;
foreach ($sameMode as $i => $r) {
    if ($r['timestamp'] === $entry['timestamp'] && $r['nome'] === $entry['nome'] && $r['score'] === $entry['score']) {
        $position = $i + 1;
        break;
    }
}

// Restituisce entrambe le classifiche per refresh frontend
$adattiva = array_values(array_filter($list, function ($e) { return (isset($e['mode']) ? $e['mode'] : '') === 'adattiva'; }));
$manuale  = array_values(array_filter($list, function ($e) { return (isset($e['mode']) ? $e['mode'] : '') === 'manuale'; }));
usort($adattiva, $cmp);
usort($manuale, $cmp);

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'position' => $position,
    'totalSameMode' => count($sameMode),
    'leaderboard' => [
        'adattiva' => ['top5' => array_slice($adattiva, 0, 5), 'total' => count($adattiva)],
        'manuale'  => ['top5' => array_slice($manuale, 0, 5),  'total' => count($manuale)],
    ],
], JSON_UNESCAPED_UNICODE);
