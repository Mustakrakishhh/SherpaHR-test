<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

// Build an actual v1 database, including edits that must survive upgrading.
$path = sys_get_temp_dir() . '/sherpa-migration-' . bin2hex(random_bytes(6)) . '.sqlite';
$originalPath = getenv('DB_PATH');
try {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    $db->exec("CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
    $db->exec("INSERT INTO metadata VALUES ('schema_version','1'),('seeded_at','2026-01-01T00:00:00Z')");
    $db->exec("INSERT INTO users VALUES (101,'Existing user')");
    $db->exec("INSERT INTO projects VALUES (201,'Existing project','Custom data',101,0)");
    $db->exec("INSERT INTO project_members VALUES (201,101)");
    $db->exec("INSERT INTO tasks (id,project_id,title,status,due_at,updated_at,version) VALUES (301,201,'Edited task','blocked','2027-02-03T04:05:06Z','2026-01-02T00:00:00Z',7)");
    $db->exec("INSERT INTO comments VALUES (401,301,101,'Existing discussion','2026-01-02T01:00:00Z')");
    $db = null;
    putenv('DB_PATH=' . $path);
    $db = Database::connect();
    $task = $db->query('SELECT * FROM tasks WHERE id=301')->fetch();
    $comment = $db->query('SELECT * FROM comments WHERE id=401')->fetch();
    $checks = [
        'version upgraded to 2' => $db->query("SELECT value FROM metadata WHERE key='schema_version'")->fetchColumn() === '2',
        'edited task fields and version preserved' => $task['title'] === 'Edited task' && $task['version'] === 7 && $task['due_at'] === '2027-02-03T04:05:06Z',
        'discussion and timestamp preserved' => $comment['body'] === 'Existing discussion' && $comment['created_at'] === '2026-01-02T01:00:00Z',
        'request fields initialized without marking old messages explicit' => $comment['help_requested'] === 0 && $comment['handled_at'] === null && $comment['handled_by'] === null && $comment['attention_version'] === 0,
        'original seed date preserved' => $db->query("SELECT value FROM metadata WHERE key='seeded_at'")->fetchColumn() === '2026-01-01T00:00:00Z',
        'no demo reseeding' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1,
    ];
    $db = null;
    $db = Database::connect();
    $checks['reopening upgraded database is idempotent'] = (int) $db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === 1
        && (int) $db->query('SELECT COUNT(*) FROM comments')->fetchColumn() === 1;
    foreach ($checks as $label => $passed) {
        if (!$passed) throw new RuntimeException('FAIL: ' . $label);
        echo 'PASS: ' . $label . PHP_EOL;
    }
    echo 'PASS: ' . count($checks) . " migration checks\n";
} finally {
    $db = null;
    putenv($originalPath === false ? 'DB_PATH' : 'DB_PATH=' . $originalPath);
    if (is_file($path)) unlink($path);
}
