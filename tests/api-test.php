<?php
/**
 * Test end-to-end delle API: gioca quiz completi contro `php -S`.
 * Lanciato da tests/run.sh, che prepara APP_URL e APP_DIR.
 */

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8765';
$appDir = getenv('APP_DIR');
$callsFile = getenv('MOCK_CALLS_FILE');

$dataset = json_decode(file_get_contents("$appDir/data/materie.json"), true);
$answers = [];
foreach ($dataset['materie'] as $m) {
    foreach ($m['domande'] as $i => $q) $answers[$m['nome'] . '::' . $i] = $q;
}

final class Client {
    private string $jar;
    public function __construct(private string $base) {
        $this->jar = tempnam(sys_get_temp_dir(), 'tc-jar');
    }
    /** @return array{0:int,1:mixed} */
    public function post(string $path, array $body): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode((string)$raw, true)];
    }
}

$failures = 0;
$checks = 0;
function check(bool $ok, string $what): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL $what\n"; }
}
function section(string $name): void { echo "-- $name\n"; }

function cfg(array $over = []): array {
    return array_merge([
        'mode' => 'manuale', 'spec' => 100, 'excluded' => [], 'qcount' => 20,
        'selectedRanges' => ['facile', 'medio'], 'answerType' => 'multiple',
    ], $over);
}

/** Avvia o continua un quiz; restituisce [codice, batch]. */
function batch(Client $c, array $cfg, array $seen): array {
    [$code, $res] = $c->post('/api/get-batch.php', ['cfg' => $cfg, 'seenIds' => $seen]);
    return [$code, $res['batch'] ?? null];
}

function grade(Client $c, array $batch, callable $answerFor, string $type = 'multiple'): array {
    $items = [];
    foreach ($batch as $q) {
        $items[] = $type === 'multiple'
            ? ['_id' => $q['_id'], 'scelta_utente' => $answerFor($q)]
            : ['_id' => $q['_id'], 'testo_utente' => $answerFor($q)];
    }
    return $c->post('/api/grade-batch.php', ['answerType' => $type, 'items' => $items]);
}

/** Gioca un quiz intero a scelta multipla; restituisce gli id serviti. */
function play(Client $c, array $cfg, callable $answerFor): array {
    $seen = [];
    while (count($seen) < $cfg['qcount']) {
        [$code, $b] = batch($c, $cfg, $seen);
        if ($code !== 200 || !$b) break;
        foreach ($b as $q) $seen[] = $q['_id'];
        grade($c, $b, $answerFor);
    }
    return $seen;
}

function mock_calls(): int {
    global $callsFile;
    return is_file($callsFile) ? (int)file_get_contents($callsFile) : 0;
}

$right = fn($q) => $answers[$q['_id']]['risposta'];
$wrong = function ($q) use ($answers) {
    foreach ($q['opzioni'] as $o) if ($o !== $answers[$q['_id']]['risposta']) return $o;
    return '';
};

section('get-batch non espone le risposte');
$c = new Client($base);
[$code, $b] = batch($c, cfg(), []);
check($code === 200 && count($b) === 5, 'primo batch da 5');
check(!array_key_exists('risposta', $b[0]), 'nessun campo risposta');
check(count($b[0]['opzioni'] ?? []) >= 2, 'opzioni presenti');

section('la pagina successiva arriva solo dopo la valutazione');
[$code] = batch($c, cfg(), array_column($b, '_id'));
check($code === 409, 'get-batch senza valutare → 409');

section('si valutano solo domande servite, una volta sola');
$foreign = null;
foreach ($answers as $id => $_) if (!in_array($id, array_column($b, '_id'), true)) { $foreign = $id; break; }
[$code, $res] = $c->post('/api/grade-batch.php', ['answerType' => 'multiple', 'items' => [
    ['_id' => $foreign, 'scelta_utente' => $answers[$foreign]['risposta']],
]]);
check($code === 200 && $res['scores'][0]['score'] === 0, 'domanda non servita → 0');
[, $first] = grade($c, $b, $wrong);
[, $again] = grade($c, $b, $right);
check(array_column($first['scores'], 'score') === [0, 0, 0, 0, 0], 'risposte sbagliate → 0');
check(array_column($again['scores'], 'score') === [0, 0, 0, 0, 0], 'rivalutare non cambia il punteggio');
check(array_column($again['scores'], '_id') === array_column($b, '_id'), 'ordine dei risultati = ordine degli item');

section('save-result rifiuta quiz incompleti');
[$code] = $c->post('/api/save-result.php', ['nome' => 'Furbo', 'score' => 100]);
check($code === 409, 'quiz a metà → 409');

section('save-result ignora il punteggio mandato dal browser');
$c = new Client($base);
$served = play($c, cfg(), $wrong);
check(count($served) === 20, '20 domande servite');
check(count(array_unique($served)) === 20, 'nessuna domanda ripetuta');
[$code] = batch($c, cfg(), $served);
[$code, $res] = $c->post('/api/save-result.php', [
    'nome' => 'Falsario', 'score' => 100, 'avgDifficulty' => 100, 'spec' => 100, 'answerType' => 'open', 'mode' => 'adattiva',
]);
check($code === 200 && ($res['ok'] ?? false), 'salvataggio ok');
$entry = null;
foreach ($res['leaderboard']['manuale']['top5'] ?? [] as $e) if ($e['nome'] === 'Falsario') $entry = $e;
check($entry !== null && $entry['score'] === 0, 'punteggio salvato = 0, non 100');
check($entry !== null && $entry['answerType'] === 'multiple' && $entry['mode'] === 'manuale', 'configurazione dal server');
check($entry !== null && $entry['avgDiff'] <= 50, 'difficoltà media reale');
[$code] = $c->post('/api/save-result.php', ['nome' => 'Falsario']);
check($code === 409, 'secondo salvataggio → 409');

section('punteggio pieno e livello adattivo calcolati dal server');
$c = new Client($base);
$cfgA = cfg(['mode' => 'adattiva', 'selectedRanges' => []]);
[$code, $b1] = batch($c, $cfgA, []);
check(max(array_column($b1, 'difficolta')) <= 25, 'prima pagina facile');
grade($c, $b1, $right);
[$code, $b2] = batch($c, $cfgA + ['currentLevel' => 'esperto'], array_column($b1, '_id'));
$d2 = array_column($b2, 'difficolta');
check(min($d2) >= 26 && max($d2) <= 50, 'dopo 5 giuste si sale a medio (il livello del client è ignorato)');
grade($c, $b2, $right);
$seen = array_merge(array_column($b1, '_id'), array_column($b2, '_id'));
while (count($seen) < 20) {
    [, $b] = batch($c, $cfgA, $seen);
    grade($c, $b, $right);
    foreach ($b as $q) $seen[] = $q['_id'];
}
[$code, $res] = $c->post('/api/save-result.php', ['nome' => 'Brava', 'score' => 3]);
$entry = null;
foreach ($res['leaderboard']['adattiva']['top5'] ?? [] as $e) if ($e['nome'] === 'Brava') $entry = $e;
check($entry !== null && $entry['score'] === 100, 'tutte giuste → 100');
check($entry !== null && $entry['level'] === 'esperto', 'livello raggiunto: esperto');

section('risposte aperte: chiamate al modello e limite per IP');
$before = mock_calls();
$c = new Client($base);
$cfgO = cfg(['answerType' => 'open']);
[, $b] = batch($c, $cfgO, []);
check(!isset($b[0]['opzioni']), 'nessuna opzione nelle aperte');
$i = 0;
[$code, $res] = grade($c, $b, function () use (&$i) { return $i++ === 0 ? '' : 'giusto'; }, 'open');
check($code === 200, 'valutazione aperta ok');
check(array_column($res['scores'], 'score') === [0, 95, 95, 95, 95], 'vuota → 0 senza chiamata, le altre dal modello');
check(mock_calls() - $before === 4, '4 chiamate (la vuota non costa)');
$last = json_decode(file_get_contents($callsFile . '.last'), true);
check(($last['model'] ?? '') === 'claude-haiku-5-5', 'modello claude-haiku-5-5');
check(!array_key_exists('temperature', $last), 'nessun temperature (Haiku 5.5 lo rifiuta)');
grade($c, $b, fn() => 'giusto', 'open');
check(mock_calls() - $before === 4, 'rivalutare non richiama il modello');
$seen = array_column($b, '_id');
[, $b] = batch($c, $cfgO, $seen);
grade($c, $b, fn() => 'giusto', 'open');                       // 4 + 5 = 9 su 12
$seen = array_merge($seen, array_column($b, '_id'));
[, $b] = batch($c, $cfgO, $seen);
[$code, $res] = grade($c, $b, fn() => 'giusto', 'open');       // 9 + 5 > 12
check($code === 429, 'oltre il limite per IP → 429');
check(is_string($res['error'] ?? null), 'messaggio di errore per il quiz');
check(mock_calls() - $before === 9, 'nessuna chiamata oltre il limite');

section('tetto globale giornaliero');
$conf = file_get_contents("$appDir/.config.php");
file_put_contents("$appDir/.config.php", str_replace(
    ["'RATE_LIMIT_OPEN_PER_IP_HOUR', 12", "'RATE_LIMIT_OPEN_GLOBAL_DAY', 1000"],
    ["'RATE_LIMIT_OPEN_PER_IP_HOUR', 1000", "'RATE_LIMIT_OPEN_GLOBAL_DAY', 3"],
    $conf
));
@unlink("$appDir/data/.ratelimit.json");
$before = mock_calls();
$c = new Client($base);
[, $b] = batch($c, $cfgO, []);
[$code] = grade($c, array_slice($b, 0, 3), fn() => 'giusto', 'open');
check($code === 200, 'dentro il tetto globale');
[$code] = grade($c, array_slice($b, 3), fn() => 'giusto', 'open');
check($code === 429, 'oltre il tetto globale → 429');
check(mock_calls() - $before === 3, 'solo 3 chiamate');
file_put_contents("$appDir/.config.php", $conf);

echo "$checks controlli, $failures falliti\n";
exit($failures ? 1 : 0);
