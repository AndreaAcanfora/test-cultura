<?php
/**
 * Restituisce 5 domande server-side in base alla configurazione del quiz.
 * NON espone mai la risposta corretta.
 */

define('ENTRY_POINT', true);
require_once __DIR__ . '/../.config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$payload = json_decode($body, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$cfg = isset($payload['cfg']) && is_array($payload['cfg']) ? $payload['cfg'] : [];
$seenIds = isset($payload['seenIds']) && is_array($payload['seenIds']) ? $payload['seenIds'] : [];
$currentLevel = isset($payload['currentLevel']) ? (string)$payload['currentLevel'] : '';

// Validazione config
$mode = isset($cfg['mode']) && in_array($cfg['mode'], ['adattiva', 'manuale'], true) ? $cfg['mode'] : 'adattiva';
$spec = isset($cfg['spec']) ? max(20, min(100, (int)$cfg['spec'])) : 50;
$excluded = isset($cfg['excluded']) && is_array($cfg['excluded']) ? array_values(array_filter($cfg['excluded'], 'is_string')) : [];
$answerType = isset($cfg['answerType']) && in_array($cfg['answerType'], ['multiple', 'open'], true) ? $cfg['answerType'] : 'multiple';
$selectedRanges = isset($cfg['selectedRanges']) && is_array($cfg['selectedRanges'])
    ? array_values(array_filter($cfg['selectedRanges'], function ($r) { return in_array($r, ['facile','medio','difficile','esperto'], true); }))
    : [];

$RANGES = [
    'facile'    => [5, 25],
    'medio'     => [26, 50],
    'difficile' => [51, 75],
    'esperto'   => [76, 100],
];

// Carica dataset
$datasetPath = __DIR__ . '/../data/materie.json';
$rawData = @file_get_contents($datasetPath);
$dataset = $rawData ? json_decode($rawData, true) : null;
if (!$dataset || !isset($dataset['materie'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Dataset not available']);
    exit;
}

$seenSet = array_flip($seenIds);

// Costruisci pool filtrato
$pool = [];
foreach ($dataset['materie'] as $m) {
    if ((int)$m['specificita'] > $spec) continue;
    if (in_array($m['nome'], $excluded, true)) continue;
    foreach ($m['domande'] as $i => $q) {
        $id = $m['nome'] . '::' . $i;
        if (isset($seenSet[$id])) continue;
        $diff = (int)($q['difficolta'] ?? 0);
        $pool[] = [
            '_id' => $id,
            'materia' => $m['nome'],
            'specificita' => (int)$m['specificita'],
            'domanda' => $q['domanda'],
            'difficolta' => $diff,
            'risposta' => $q['risposta'],
            'opzioni' => isset($q['opzioni']) && is_array($q['opzioni']) ? array_values($q['opzioni']) : null,
        ];
    }
}

// Filtra per livello (adattiva) o range (manuale)
$candidates = [];
if ($mode === 'adattiva') {
    $level = in_array($currentLevel, ['facile','medio','difficile','esperto'], true) ? $currentLevel : 'facile';
    list($min, $max) = $RANGES[$level];
    foreach ($pool as $q) {
        if ($q['difficolta'] >= $min && $q['difficolta'] <= $max) $candidates[] = $q;
    }
    // Fallback su livelli adiacenti se troppo scarsi
    if (count($candidates) < 5) {
        foreach (['facile','medio','difficile','esperto'] as $lvl) {
            if ($lvl === $level) continue;
            list($mn, $mx) = $RANGES[$lvl];
            foreach ($pool as $q) {
                if ($q['difficolta'] >= $mn && $q['difficolta'] <= $mx) $candidates[] = $q;
            }
            if (count($candidates) >= 5) break;
        }
    }
} else {
    // Manuale: unione dei range selezionati
    if (empty($selectedRanges)) $selectedRanges = ['facile'];
    foreach ($pool as $q) {
        foreach ($selectedRanges as $r) {
            list($mn, $mx) = $RANGES[$r];
            if ($q['difficolta'] >= $mn && $q['difficolta'] <= $mx) {
                $candidates[] = $q;
                break;
            }
        }
    }
}

// Sample diverse: priorità a materie non viste in questo quiz
$seenMaterie = [];
foreach ($seenIds as $sid) {
    $parts = explode('::', $sid, 2);
    if ($parts && isset($parts[0])) $seenMaterie[$parts[0]] = true;
}

// Raggruppa per materia
$byMateria = [];
foreach ($candidates as $q) {
    $byMateria[$q['materia']][] = $q;
}
$mai = [];
$gia = [];
foreach (array_keys($byMateria) as $name) {
    if (isset($seenMaterie[$name])) $gia[] = $name;
    else $mai[] = $name;
}
shuffle($mai);
shuffle($gia);

$N = 5;
$out = [];
$usedMateria = [];
foreach ([$mai, $gia] as $list) {
    foreach ($list as $name) {
        if (count($out) >= $N) break;
        if (isset($usedMateria[$name])) continue;
        $opts = $byMateria[$name];
        $out[] = $opts[array_rand($opts)];
        $usedMateria[$name] = true;
    }
    if (count($out) >= $N) break;
}

// Se ancora ne servono, riempi accettando ripetizioni
if (count($out) < $N) {
    $pickedIds = [];
    foreach ($out as $q) $pickedIds[$q['_id']] = true;
    $remaining = [];
    foreach ($candidates as $q) if (!isset($pickedIds[$q['_id']])) $remaining[] = $q;
    shuffle($remaining);
    while (count($out) < $N && !empty($remaining)) {
        $out[] = array_shift($remaining);
    }
}

// Sanitizza output: rimuovi 'risposta', mescola opzioni se richiesto
$batch = [];
foreach ($out as $q) {
    $item = [
        '_id' => $q['_id'],
        'materia' => $q['materia'],
        'specificita' => $q['specificita'],
        'domanda' => $q['domanda'],
        'difficolta' => $q['difficolta'],
    ];
    if ($answerType === 'multiple' && is_array($q['opzioni']) && count($q['opzioni']) >= 2) {
        $opts = $q['opzioni'];
        shuffle($opts);
        $item['opzioni'] = array_values($opts);
    }
    // Se answerType=multiple ma opzioni mancanti (caso raro durante migrazione), lasciamo senza opzioni
    $batch[] = $item;
}

shuffle($batch);

echo json_encode(['batch' => $batch], JSON_UNESCAPED_UNICODE);
