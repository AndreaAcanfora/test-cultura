<?php
/**
 * Controlla il dataset: struttura, difficoltà, opzioni e coerenza col manifest.
 * Uso: php tests/validate-data.php
 */

$root = dirname(__DIR__);
$errors = [];

$data = json_decode((string)file_get_contents("$root/data/materie.json"), true);
$manifest = json_decode((string)file_get_contents("$root/data/manifest.json"), true);
if (!is_array($data) || !isset($data['materie']) || !is_array($data['materie'])) {
    fwrite(STDERR, "data/materie.json: JSON non valido o senza 'materie'\n");
    exit(1);
}
if (!is_array($manifest)) {
    fwrite(STDERR, "data/manifest.json: JSON non valido\n");
    exit(1);
}

$names = [];
$total = 0;
foreach ($data['materie'] as $mi => $m) {
    $nome = $m['nome'] ?? null;
    if (!is_string($nome) || $nome === '') { $errors[] = "materia #$mi senza nome"; continue; }
    if (isset($names[$nome])) $errors[] = "materia duplicata: $nome";
    $names[$nome] = $m;
    if (!is_int($m['specificita'] ?? null)) $errors[] = "$nome: specificita mancante";
    if (empty($m['domande']) || !is_array($m['domande'])) { $errors[] = "$nome: nessuna domanda"; continue; }
    $seen = [];
    foreach ($m['domande'] as $i => $q) {
        $total++;
        $where = "$nome::$i";
        foreach (['domanda', 'risposta'] as $k) {
            if (!is_string($q[$k] ?? null) || trim($q[$k]) === '') $errors[] = "$where: '$k' mancante";
        }
        $d = $q['difficolta'] ?? null;
        if (!is_int($d) || $d < 5 || $d > 100) $errors[] = "$where: difficolta fuori range (5-100)";
        $opts = $q['opzioni'] ?? null;
        if (!is_array($opts) || count($opts) < 2) {
            $errors[] = "$where: servono almeno 2 opzioni";
        } else {
            if (!in_array($q['risposta'] ?? null, $opts, true)) $errors[] = "$where: la risposta non è tra le opzioni";
            if (count(array_unique($opts)) !== count($opts)) $errors[] = "$where: opzioni duplicate";
        }
        $key = mb_strtolower(trim((string)($q['domanda'] ?? '')));
        if (isset($seen[$key])) $errors[] = "$where: domanda duplicata di $nome::{$seen[$key]}";
        $seen[$key] = $i;
    }
}

$manifestNames = [];
foreach ($manifest as $entry) {
    $nome = $entry['nome'] ?? '';
    $manifestNames[$nome] = true;
    if (!isset($names[$nome])) { $errors[] = "manifest: '$nome' non esiste nel dataset"; continue; }
    if (($entry['specificita'] ?? null) !== $names[$nome]['specificita']) $errors[] = "manifest: specificita diversa per $nome";
}
foreach ($names as $nome => $_) {
    if (!isset($manifestNames[$nome])) $errors[] = "manifest: manca '$nome'";
}

if ($errors) {
    fwrite(STDERR, implode("\n", array_slice($errors, 0, 50)) . "\n");
    fwrite(STDERR, count($errors) . " errori\n");
    exit(1);
}
echo "dataset ok: " . count($names) . " materie, $total domande\n";
