<?php

declare(strict_types=1);

namespace AutoLepao\Support;

use AutoLepao\Core\Problem;
use PDO;

final class Database
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS accounts (
                id TEXT PRIMARY KEY, uid TEXT NOT NULL UNIQUE, provider TEXT NOT NULL,
                openid TEXT NOT NULL, nickname TEXT NOT NULL, credentials TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'valid', message TEXT NOT NULL DEFAULT '',
                overview TEXT, updated_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS account_identity ON accounts(provider, openid);
            CREATE TABLE IF NOT EXISTS bindings (
                id TEXT PRIMARY KEY, provider TEXT NOT NULL, state TEXT NOT NULL,
                context TEXT NOT NULL, expires INTEGER NOT NULL, message TEXT NOT NULL DEFAULT '', account_id TEXT
            );
            CREATE TABLE IF NOT EXISTS routes (id TEXT PRIMARY KEY, name TEXT NOT NULL, config TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS tasks (
                id TEXT PRIMARY KEY, account_id TEXT NOT NULL REFERENCES accounts(id),
                state TEXT NOT NULL, due_at INTEGER NOT NULL, created_at INTEGER NOT NULL,
                started_at INTEGER, config TEXT NOT NULL, policy TEXT NOT NULL,
                context TEXT, result TEXT, message TEXT NOT NULL DEFAULT '', mode TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS tasks_due ON tasks(state, due_at);
            CREATE UNIQUE INDEX IF NOT EXISTS one_active_task_per_account ON tasks(account_id)
                WHERE state IN ('scheduled','preparing','running','submitting');
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, task_id TEXT NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                at INTEGER NOT NULL, message TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS events_task ON events(task_id, id);
            CREATE TABLE IF NOT EXISTS hosting (
                account_id TEXT PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE,
                state TEXT NOT NULL, pause_reason TEXT, target INTEGER NOT NULL,
                completed INTEGER NOT NULL DEFAULT 0, term_id TEXT NOT NULL,
                config TEXT NOT NULL, submission_ip TEXT NOT NULL, next_at INTEGER NOT NULL,
                message TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS hosting_due ON hosting(state, next_at);
            SQL);
    }

    public function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function account(string $id): array
    {
        return $this->one('SELECT * FROM accounts WHERE id=?', [$id]) ?? throw new Problem('账号不存在', 404);
    }

    public function task(string $id): array
    {
        return $this->one('SELECT * FROM tasks WHERE id=?', [$id]) ?? throw new Problem('任务不存在', 404);
    }

    public function event(string $task, string $message): void
    {
        $this->execute('INSERT INTO events(task_id,at,message) VALUES(?,?,?)', [$task, time(), $message]);
    }

    public function transition(string $id, string $state, string $message, array $fields = []): void
    {
        $this->transaction(function () use ($id, $state, $message, $fields) {
            $values = [$state, $message];
            $sql = 'UPDATE tasks SET state=?, message=?';
            foreach ($fields as $key => $value) {
                if (!in_array($key, ['due_at','started_at','config','policy','context','result'], true)) {
                    throw new \LogicException('Unsupported task column');
                }
                $sql .= ', '.$key.'=?';
                $values[] = $value;
            }
            $values[] = $id;
            $this->execute($sql.' WHERE id=?', $values);
            $this->event($id, $message);
        });
    }
}
