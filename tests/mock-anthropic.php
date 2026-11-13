<?php
/**
 * Finta Messages API per i test: niente rete, niente chiave.
 * Risponde con submit_grade: 95 se la risposta contiene "giusto", altrimenti 10.
 * Conta le chiamate in MOCK_CALLS_FILE e registra l'ultimo payload.
 */

$body = json_decode((string)file_get_contents('php://input'), true);
$counter = getenv('MOCK_CALLS_FILE') ?: sys_get_temp_dir() . '/mock-anthropic-calls';
$fp = fopen($counter, 'c+');
flock($fp, LOCK_EX);
$n = (int)stream_get_contents($fp) + 1;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, (string)$n);
flock($fp, LOCK_UN);
fclose($fp);
file_put_contents($counter . '.last', json_encode($body));

header('Content-Type: application/json');
if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '' || !is_array($body)) {
    http_response_code(401);
    echo '{"type":"error"}';
    exit;
}
$text = $body['messages'][0]['content'] ?? '';
$answer = preg_match('~<student_answer>(.*)</student_answer>~s', $text, $m) ? $m[1] : '';
$score = stripos($answer, 'giusto') !== false ? 95 : 10;
echo json_encode([
    'type' => 'message',
    'role' => 'assistant',
    'stop_reason' => 'tool_use',
    'content' => [[
        'type' => 'tool_use',
        'id' => 'toolu_test',
        'name' => 'submit_grade',
        'input' => ['score' => $score, 'motivazione' => 'test'],
    ]],
]);
