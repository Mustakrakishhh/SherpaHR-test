<?php
declare(strict_types=1);

/** A transparent triage aid, not a classifier of the discussion's meaning. */
final class Attention
{
    private const PHRASES = [
        'нужна помощь руководителя', 'нужна помощь', 'помоги', 'помогите',
        'не можем продолжить', 'нужно решение', 'нужен ответ',
    ];

    public function __construct(private PDO $db) {}

    public function forUser(int $userId, DateTimeImmutable $now): array
    {
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        // Membership is checked as well as ownership. Never aggregate first and filter in the UI.
        // Task counts and discussion are fetched in batches, avoiding a query per card.
        $query = $this->db->prepare(<<<'SQL'
            SELECT t.*, p.name AS project_name,
                (SELECT COUNT(*) FROM comments total WHERE total.task_id=t.id) AS comment_count
            FROM tasks t
            JOIN projects p ON p.id=t.project_id
            JOIN project_members m ON m.project_id=p.id AND m.user_id=?
            WHERE p.owner_id=? AND p.archived=0 AND t.status<>'done'
            ORDER BY t.id
            SQL);
        $query->execute([$userId, $userId]);
        $tasks = [];
        foreach ($query->fetchAll() as $row) {
            $row['reasons'] = self::reasons($row, $stamp);
            $tasks[] = $row;
        }
        usort($tasks, static function (array $a, array $b): int {
            return (($a['due_at'] ?? '9999') <=> ($b['due_at'] ?? '9999'))
                ?: ($a['id'] <=> $b['id']);
        });
        // Inspect all discussion, not only the latest comment: a later reply can
        // contain useful context without repeating an earlier request for help.
        $comments = $this->db->prepare(<<<'SQL'
            SELECT c.*, u.name AS author, handler.name AS handled_by_name FROM comments c
            JOIN users u ON u.id=c.user_id
            LEFT JOIN users handler ON handler.id=c.handled_by
            JOIN tasks t ON t.id=c.task_id
            JOIN projects p ON p.id=t.project_id
            JOIN project_members m ON m.project_id=p.id AND m.user_id=?
            WHERE p.owner_id=? AND p.archived=0 AND t.status<>'done'
            ORDER BY c.id DESC
            SQL);
        $comments->execute([$userId, $userId]);
        $signals = [];
        $lastComments = [];
        $requestCounts = [];
        while ($comment = $comments->fetch()) {
            $comment = self::comment($comment);
            $taskId = $comment['task_id'];
            $lastComments[$taskId] ??= $comment;
            if ($comment['is_request'] && !$comment['handled']) {
                $requestCounts[$taskId] = ($requestCounts[$taskId] ?? 0) + 1;
                $signals[$taskId] ??= [
                    'comment' => $comment, 'matched_phrase' => $comment['matched_phrase'],
                    'explicit' => $comment['help_requested'],
                ];
            }
        }
        foreach ($tasks as &$task) {
            $task['last_comment'] = $lastComments[$task['id']] ?? null;
            $task['open_request_count'] = $requestCounts[$task['id']] ?? 0;
            $task['discussion_signal'] = $signals[$task['id']] ?? null;
            if ($task['discussion_signal'] !== null) $task['reasons'][] = 'discussion';
        }
        unset($task);
        return ['generated_at' => $stamp, 'tasks' => $tasks];
    }

    public static function comment(array $comment): array
    {
        $comment['help_requested'] = (bool) $comment['help_requested'];
        $comment['handled'] = $comment['handled_at'] !== null;
        $comment['matched_phrase'] = self::matchingPhrase($comment['body']);
        $comment['is_request'] = $comment['help_requested'] || $comment['matched_phrase'] !== null;
        return $comment;
    }

    public static function matchingPhrase(string $body): ?string
    {
        $body = preg_replace('/\s+/u', ' ', $body);
        foreach (self::PHRASES as $phrase) {
            // Unicode boundaries avoid matching «помоги» inside «помогите» or
            // longer words; whitespace and case are insignificant.
            if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}_])/iu', $body)) {
                return $phrase;
            }
        }
        return null;
    }

    public static function reasons(array $task, string $now): array
    {
        if ($task['status'] === 'done') return [];
        $reasons = [];
        if ($task['status'] === 'blocked') $reasons[] = 'blocked';
        // API dates are canonical UTC strings; equal deadlines are not overdue yet.
        if ($task['due_at'] !== null && $task['due_at'] < $now) $reasons[] = 'overdue';
        if ($task['assignee_id'] === null) $reasons[] = 'unassigned';
        return $reasons;
    }
}
