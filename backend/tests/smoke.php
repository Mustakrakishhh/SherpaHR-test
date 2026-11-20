<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Attention.php';

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    $clock = '2026-11-20T12:00:00Z';
    $sample = ['status' => 'open', 'assignee_id' => 2, 'due_at' => $clock];
    check(Attention::reasons($sample, $clock) === [], 'deadline equal to server clock is not overdue');
    check(Attention::reasons(array_merge($sample, ['due_at' => '2026-11-20T11:59:59Z']), $clock) === ['overdue'], 'deadline one second before clock is overdue');
    check(Attention::reasons(array_merge($sample, ['status' => 'done', 'assignee_id' => null]), $clock) === [], 'completed task has no attention signals');
    check(Attention::matchingPhrase("НУЖНА\n  ПОМОЩЬ руководителя с выбором") === 'нужна помощь руководителя', 'phrase match handles Unicode case and multiline whitespace');
    check(Attention::matchingPhrase('Анна, помогите с выбором.') === 'помогите', 'phrase matches whole words');
    check(Attention::matchingPhrase('Сверка идёт по плану, препятствий нет.') === null, 'ordinary discussion has no request signal');
    check(Attention::matchingPhrase('помогитека') === null, 'partial word does not trigger discussion signal');
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    [$status, $response] = request('GET', '/api/attention');
    $overview = $response['data'];
    $overviewTasks = array_column($overview['tasks'], null, 'id');
    check($status === 200 && isset($overview['generated_at']), 'overview includes server snapshot time');
    check(count($overviewTasks) === 8 && isset($overviewTasks[4], $overviewTasks[7], $overviewTasks[8]), 'overview includes every unfinished task of owned active projects');
    check(!isset($overviewTasks[3], $overviewTasks[9], $overviewTasks[10], $overviewTasks[11]), 'overview excludes completed, archived and foreign project tasks');
    check($overviewTasks[7]['reasons'] === ['unassigned', 'discussion'] && $overviewTasks[7]['last_comment']['user_id'] === 2, 'unassigned task and discussion are present in overview');
    check($overviewTasks[1]['discussion_signal']['comment']['user_id'] === 2 && $overviewTasks[1]['last_comment']['user_id'] === 3, 'earlier request remains visible alongside newer non-matching reply');
    check(in_array('discussion', $overviewTasks[7]['reasons'], true) && $overviewTasks[7]['status'] === 'open', 'discussion signal works without blocked status');
    check($overviewTasks[1]['comment_count'] === 2 && $overviewTasks[1]['last_comment']['author'] === 'Вера · аналитик', 'overview includes latest comment and full discussion count');
    check($overviewTasks[12]['last_comment'] === null && $overviewTasks[12]['comment_count'] === 0, 'task without discussion has a null preview');
    check(request('GET', '/api/attention', null, 2)[1]['data']['tasks'] === [], 'membership alone does not grant a manager overview');
    check(array_column(request('GET', '/api/attention', null, 3)[1]['data']['tasks'], 'id') === [11], 'Vera overview contains only her own project');
    check(request('GET', '/api/attention', null, 999)[0] === 401, 'overview rejects unknown persona');
    check(array_column(request('GET', '/api/projects/2/tasks')[1]['data'], 'id') === [5, 6, 7], 'project list retains task without assignee');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => 'Явная просьба', 'help_requested' => true], 2)[0] === 409, 'completed task rejects explicit help request');
    check(request('GET', '/api/tasks/' . $task['id'])[1]['data']['comments'] === [], 'rejected explicit request does not insert a comment');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1])[0] === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => str_repeat('я', 2501)])[0] === 422, 'comment byte limit applies to UTF-8');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => 'Spoof', 'user_id' => 1], 2)[0] === 422, 'comment author cannot be supplied by caller');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => 'Test', 'help_requested' => 'true'])[0] === 422, 'help request flag requires JSON boolean');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => 'Test', 'help_requested' => null])[0] === 422, 'null help request flag rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check(count($response['data']) === 6, 'initialization does not duplicate or reset data');
    check(in_array($task['id'], array_column($response['data'], 'id'), true), 'task remains listed after assignee is cleared');

    // Exercise triage lifecycle using dates relative to the actual server clock.
    $past = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Attention lifecycle', 'status' => 'blocked', 'due_at' => $past,
    ]);
    $triage = $response['data'];
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['reasons'] === ['blocked', 'overdue', 'unassigned'], 'multiple attention reasons combine in a single task');
    $ordered = request('GET', '/api/attention')[1]['data']['tasks'];
    $actualOrder = array_column($ordered, 'id');
    usort($ordered, static fn(array $a, array $b): int => (($a['due_at'] ?? '9999') <=> ($b['due_at'] ?? '9999')) ?: ($a['id'] <=> $b['id']));
    check($actualOrder === array_column($ordered, 'id'), 'overview is ordered by deadline with undated tasks last regardless of reason');
    [$status, $response] = request('POST', '/api/tasks/' . $triage['id'] . '/comments', ['body' => '  Обсудим доступ  '], 2);
    $commentId = $response['data']['id'];
    check($status === 201 && $response['data']['body'] === 'Обсудим доступ' && $response['data']['user_id'] === 2, 'comment response contains persisted trimmed body and acting author');
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['last_comment']['id'] === $commentId && $index[$triage['id']]['comment_count'] === 1, 'new comment updates overview preview across requests');
    [$status, $response] = request('POST', '/api/tasks/' . $triage['id'] . '/comments', ['body' => 'Нужен ответ руководителя'], 2);
    $keywordId = $response['data']['id'];
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['discussion_signal']['matched_phrase'] === 'нужен ответ', 'new request updates discussion signal');

    [$status, $response] = request('POST', '/api/tasks/' . $triage['id'] . '/comments', ['body' => 'Какой вариант выбираем: А или Б?', 'help_requested' => true], 2);
    $explicit = $response['data'];
    check($status === 201 && $explicit['help_requested'] === true && $explicit['is_request'] === true && $explicit['matched_phrase'] === null, 'explicit request works without keyword and returns boolean metadata');
    $markerPath = '/api/tasks/' . $triage['id'] . '/comments/' . $explicit['id'] . '/attention';
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['discussion_signal']['explicit'] === true && $index[$triage['id']]['open_request_count'] === 2, 'overview distinguishes explicit request and counts separate open requests');
    check(request('PATCH', $markerPath, ['handled' => true, 'version' => 0], 2)[0] === 403, 'request author cannot process request without manager role');
    check(request('PATCH', $markerPath, ['handled' => true, 'version' => 0], 3)[0] === 403, 'other project member cannot process request');
    check(request('PATCH', '/api/tasks/11/comments/' . $explicit['id'] . '/attention', ['handled' => true, 'version' => 0])[0] === 404, 'hidden task cannot be accessed through request marker endpoint');
    check(request('PATCH', '/api/tasks/' . $task['id'] . '/comments/' . $explicit['id'] . '/attention', ['handled' => true, 'version' => 0])[0] === 404, 'comment must belong to task in marker URL');
    check(request('PATCH', '/api/tasks/10/comments/' . $explicit['id'] . '/attention', ['handled' => true, 'version' => 0])[0] === 409, 'archive rejects request state changes');
    check(request('PATCH', $markerPath, ['handled' => true])[0] === 422, 'request marker update requires version');
    check(request('PATCH', $markerPath, ['handled' => 'true', 'version' => 0])[0] === 422, 'request handled state requires JSON boolean');
    check(request('PATCH', $markerPath, ['handled' => true, 'version' => 0, 'handled_by' => 2])[0] === 422, 'caller cannot spoof request handler');
    check(request('PATCH', '/api/tasks/' . $triage['id'] . '/comments/' . $commentId . '/attention', ['handled' => true, 'version' => 0])[0] === 422, 'ordinary comment cannot be marked as a request');
    [$status, $response] = request('PATCH', $markerPath, ['handled' => true, 'version' => 0]);
    check($status === 200 && $response['data']['handled'] === true && $response['data']['handled_by'] === 1 && $response['data']['handled_at'] !== null && $response['data']['attention_version'] === 1, 'manager processes request with server identity time and version');
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['open_request_count'] === 1 && $index[$triage['id']]['discussion_signal']['comment']['id'] === $keywordId, 'processing one request leaves an earlier unhandled request visible');
    request('PATCH', '/api/tasks/' . $triage['id'] . '/comments/' . $keywordId . '/attention', ['handled' => true, 'version' => 0]);
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['discussion_signal'] === null && $index[$triage['id']]['open_request_count'] === 0
        && $index[$triage['id']]['reasons'] === ['blocked', 'overdue', 'unassigned'], 'processing all requests preserves other attention reasons');
    [$status, $response] = request('PATCH', $markerPath, ['handled' => false, 'version' => 1]);
    check($status === 200 && $response['data']['handled'] === false && $response['data']['handled_by'] === null && $response['data']['attention_version'] === 2, 'manager can reopen processed request');
    check(request('PATCH', $markerPath, ['handled' => true, 'version' => 1])[0] === 409, 'stale tab cannot hide reopened request');
    [$status, $response] = request('POST', '/api/tasks/' . $triage['id'] . '/comments', ['body' => 'Есть ещё вопрос по второму варианту.', 'help_requested' => true], 2);
    $newRequestId = $response['data']['id'];
    request('PATCH', $markerPath, ['handled' => true, 'version' => 2]);
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['open_request_count'] === 1 && $index[$triage['id']]['discussion_signal']['comment']['id'] === $newRequestId, 'new request survives processing of an older comment');
    $history = request('GET', '/api/tasks/' . $triage['id'])[1]['data']['comments'];
    check(count($history) === 4 && $history[2]['body'] === 'Какой вариант выбираем: А или Б?' && $history[2]['handled'] === true, 'processing preserves complete discussion and original request text');
    check(request('GET', '/api/tasks/' . $triage['id'])[1]['data']['version'] === 1, 'adding comment does not invalidate independent task field version');
    request('PATCH', '/api/tasks/' . $triage['id'], ['status' => 'in_progress', 'assignee_id' => 2, 'due_at' => $future, 'version' => 1]);
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check($index[$triage['id']]['reasons'] === ['discussion'], 'resolving formal signals preserves new explicit request');
    request('PATCH', '/api/tasks/' . $triage['id'], ['status' => 'done', 'version' => 2]);
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check(!isset($index[$triage['id']]), 'completed task leaves overview after update');
    check(request('POST', '/api/tasks/' . $triage['id'] . '/comments', ['body' => 'Another explicit request', 'help_requested' => true], 2)[0] === 409, 'server blocks explicit request after another tab completes task');
    [$status, $response] = request('POST', '/api/tasks/' . $triage['id'] . '/comments', ['body' => 'Обычный комментарий после завершения', 'help_requested' => false], 2);
    check($status === 201 && $response['data']['help_requested'] === false, 'ordinary comment remains available on completed task');
    $newMarkerPath = '/api/tasks/' . $triage['id'] . '/comments/' . $newRequestId . '/attention';
    check(request('PATCH', $newMarkerPath, ['handled' => true, 'version' => 0])[0] === 200, 'manager may process historical request on completed task');
    check(request('PATCH', $newMarkerPath, ['handled' => false, 'version' => 1])[0] === 200, 'manager may clear processing marker without reopening task');
    $completed = request('GET', '/api/tasks/' . $triage['id'])[1]['data'];
    check($completed['status'] === 'done' && $completed['version'] === 3, 'request marker never automatically reopens completed task');
    check(!in_array($triage['id'], array_column(request('GET', '/api/attention')[1]['data']['tasks'], 'id'), true), 'clearing marker does not add completed task to overview');
    request('PATCH', '/api/tasks/' . $triage['id'], ['status' => 'open', 'version' => 3]);
    $index = array_column(request('GET', '/api/attention')[1]['data']['tasks'], null, 'id');
    check(isset($index[$triage['id']]) && $index[$triage['id']]['discussion_signal']['comment']['id'] === $newRequestId, 'request returns to overview only after explicit task reopening');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
