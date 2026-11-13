<?php
/**
 * Stato server-side del quiz e limiti di richieste.
 *
 * Ogni quiz ("run") vive nella sessione PHP: configurazione, domande servite,
 * punteggi assegnati dal server e livello adattivo. Il punteggio salvato in
 * classifica si ricava solo da qui, mai da valori mandati dal browser.
 */

if (!defined('ENTRY_POINT')) {
    http_response_code(403);
    exit;
}

// Limiti di default, sovrascrivibili in .config.php.
if (!defined('RATE_LIMIT_OPEN_PER_IP_HOUR')) define('RATE_LIMIT_OPEN_PER_IP_HOUR', 90);   // ~3 quiz aperti da 30 domande
if (!defined('RATE_LIMIT_OPEN_GLOBAL_DAY')) define('RATE_LIMIT_OPEN_GLOBAL_DAY', 3000);    // tetto di spesa giornaliero
if (!defined('RATE_LIMIT_RUNS_PER_IP_HOUR')) define('RATE_LIMIT_RUNS_PER_IP_HOUR', 30);
if (!defined('RATE_LIMIT_FILE')) define('RATE_LIMIT_FILE', __DIR__ . '/../data/.ratelimit.json');

const LEVEL_ORDER = ['facile', 'medio', 'difficile', 'esperto'];
const QUESTIONS_PER_PAGE = 5;

function run_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('tcsid');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Il run corrente, o null se non ce n'è uno. */
function run_get(): ?array {
    run_session_start();
    return isset($_SESSION['run']) && is_array($_SESSION['run']) ? $_SESSION['run'] : null;
}

function run_put(array $run): void {
    run_session_start();
    $_SESSION['run'] = $run;
}

function run_new(array $cfg): array {
    return [
        'cfg' => $cfg,
        'served' => [],       // _id => difficolta, in ordine di servizio
        'pages' => [],        // elenco di batch (array di _id)
        'graded' => [],       // _id => risultato restituito al client
        'level' => 'facile',
        'maxLevel' => 'facile',
        'exhausted' => false,
        'saved' => false,
        'started' => time(),
    ];
}

/**
 * Aggiorna il livello adattivo quando l'ultima pagina è stata tutta valutata.
 * Stessa regola del frontend: media ≥ 70 sale, < 40 scende.
 */
function run_update_level(array &$run): void {
    if ($run['cfg']['mode'] !== 'adattiva') return;
    $pageIdx = count($run['pages']) - 1;
    if ($pageIdx < 0 || isset($run['levelAppliedFor'][$pageIdx])) return;
    $page = $run['pages'][$pageIdx];
    $sum = 0;
    foreach ($page as $id) {
        if (!isset($run['graded'][$id])) return;
        $sum += $run['graded'][$id]['score'];
    }
    $avg = $sum / max(1, count($page));
    $idx = array_search($run['level'], LEVEL_ORDER, true);
    if ($avg >= 70 && $idx < count(LEVEL_ORDER) - 1) $idx++;
    elseif ($avg < 40 && $idx > 0) $idx--;
    $run['level'] = LEVEL_ORDER[$idx];
    if ($idx > array_search($run['maxLevel'], LEVEL_ORDER, true)) $run['maxLevel'] = $run['level'];
    $run['levelAppliedFor'][$pageIdx] = true;
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Consuma $n unità dai contatori indicati, tutti o nessuno.
 * $buckets: [[chiave, limite, finestra_in_secondi], ...]
 * Restituisce true se c'è spazio, false se un limite è stato superato.
 */
function rate_limit_take(array $buckets, int $n = 1): bool {
    if ($n <= 0) return true;
    $file = RATE_LIMIT_FILE;
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // Senza storage non possiamo contare: meglio negare che spendere senza limiti.
        error_log('[rate-limit] cannot open ' . $file);
        return false;
    }
    flock($fp, LOCK_EX);
    $state = json_decode(stream_get_contents($fp), true);
    if (!is_array($state)) $state = [];
    $now = time();

    // Pulizia delle finestre scadute
    foreach ($state as $k => $v) {
        if (!is_array($v) || ($v['reset'] ?? 0) <= $now) unset($state[$k]);
    }

    $ok = true;
    foreach ($buckets as [$key, $limit, $window]) {
        $cur = $state[$key]['count'] ?? 0;
        if ($cur + $n > $limit) { $ok = false; break; }
    }
    if ($ok) {
        foreach ($buckets as [$key, $limit, $window]) {
            if (!isset($state[$key])) $state[$key] = ['count' => 0, 'reset' => $now + $window];
            $state[$key]['count'] += $n;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function ip_bucket(string $name, int $limit, int $window): array {
    return [$name . ':' . hash('sha256', client_ip()), $limit, $window];
}

function json_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
