<?php

declare(strict_types=1);

namespace AutoLepao\App;

use AutoLepao\Core\{Crypto, Health, Problem};
use AutoLepao\Support\Wakeup;

final class Hosting
{
    public function __construct(private readonly Application $app)
    {
    }

    public static function day(int $at): string
    {
        return (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    }

    public static function tomorrow(): int
    {
        return (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Shanghai')))->getTimestamp() + 60;
    }

    public function start(array $input, string $ip): array
    {
        $id = (string) ($input['account_id'] ?? '');
        $plan = $this->app->plan($input + ['mode' => 'random'], true);
        $overview = $this->app->checkAccount($id);
        if (!$overview['available']) {
            throw new Problem($overview['message']);
        }
        $stats = $overview['overview']['semester'];
        $target = $input['target'] ?? $stats['target'];
        if (!is_int($target) || $target < 1 || $target > 500) {
            throw new Problem('请填写1到500之间的学期目标次数');
        }
        if ($stats['count'] >= $target) {
            throw new Problem('本学期目标已经完成');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new Problem('来源 IP 无效');
        }
        if (!$this->app->workerOnline()) {
            throw new Problem('请先启动调度器', 409);
        }
        $this->app->db->execute(<<<'SQL'
            INSERT INTO hosting(account_id,state,target,completed,term_id,config,submission_ip,next_at)
            VALUES(?,'active',?,?,?,?,?,?) ON CONFLICT(account_id) DO UPDATE SET
            state='active',pause_reason=NULL,target=excluded.target,completed=excluded.completed,
            term_id=excluded.term_id,config=excluded.config,submission_ip=excluded.submission_ip,
            next_at=excluded.next_at,message='已开启每日预约'
            SQL, [$id, $target, $stats['count'], $stats['term_id'], Crypto::json($input['config']), $ip, time()]);
        Wakeup::notify();
        return ['ok' => true];
    }

    public function stop(string $id): void
    {
        $this->app->db->execute("UPDATE hosting SET state='paused',pause_reason='manual',message='已手动暂停' WHERE account_id=?", [$id]);
        foreach ($this->app->db->all("SELECT id,config FROM tasks WHERE account_id=? AND state='scheduled'", [$id]) as $task) {
            if (json_decode($task['config'], true)['hosting'] ?? false) {
                $this->app->cancelTask($task['id']);
            }
        }
        Wakeup::notify();
    }

    public function tick(): void
    {
        foreach ($this->app->db->all("SELECT * FROM hosting WHERE state='active' AND next_at<=? ORDER BY next_at LIMIT 10", [time()]) as $hosting) {
            $id = $hosting['account_id'];
            try {
                $account = $this->app->db->account($id);
                $credentials = $this->app->credentials($id);
                $overview = $this->app->policy->overview($credentials);
                $stats = $overview['semester'];
                if ($stats['term_id'] !== $hosting['term_id'] || $stats['count'] >= $hosting['target']) {
                    $this->update($id, 'completed', $stats['term_id'] !== $hosting['term_id'] ? '学期已切换，请为新学期确认目标和路线' : '本学期目标已完成', $stats['count']);
                    continue;
                }
                $this->app->db->execute('UPDATE hosting SET completed=? WHERE account_id=?', [$stats['count'], $id]);
                $active = $this->app->db->one("SELECT id FROM tasks WHERE account_id=? AND state IN ('scheduled','preparing','running','submitting')", [$id]);
                if ($active) {
                    $this->later($id, '账号已有预约，等待任务结束', time() + 900);
                    continue;
                }
                // Check local uncertainty and all today's remote pages before creating another submission.
                $today = (new \DateTimeImmutable('today'))->getTimestamp();
                $local = $this->app->db->one("SELECT id FROM tasks WHERE account_id=? AND started_at>=? AND state IN ('uncertain','completed')", [$id, $today]);
                if ($local || $this->hasRemoteRecordToday($credentials)) {
                    $this->later($id, '今天已有记录，明天继续', self::tomorrow());
                    continue;
                }
                $config = json_decode($hosting['config'], true);
                $created = $this->app->createTask(['account_id' => $id, 'mode' => 'random', 'config' => $config], $hosting['submission_ip']);
                $task = $this->app->db->task($created['id']);
                $snapshot = json_decode($task['config'], true);
                $snapshot['hosting'] = true;
                $this->app->db->execute('UPDATE tasks SET config=? WHERE id=?', [Crypto::json($snapshot), $task['id']]);
                $this->later($id, '下一次已预约', self::tomorrow());
            } catch (\Throwable $error) {
                $account = $this->app->db->account($id);
                $message = $error instanceof Problem ? $error->getMessage() : '每日预约暂未完成，请查看日志';
                if ($account['status'] === 'invalid') {
                    $this->app->db->execute("UPDATE hosting SET state='paused',pause_reason='login_invalid',message=? WHERE account_id=?", [$message, $id]);
                } else {
                    $this->later($id, $message, time() + 900);
                }
                error_log('Hosting error '.get_class($error).' code='.$error->getCode());
            }
        }
    }

    private function hasRemoteRecordToday(array $credentials): bool
    {
        $day = self::day(time());
        $seen = [];
        $previous = PHP_INT_MAX;
        for ($page = 1; $page <= 20; $page++) {
            $response = $this->app->lepao->call('Run2/allRecordList', $credentials, ['page' => (string) $page, 'year' => substr($day, 0, 4)]);
            $health = Health::lepao($response);
            if ($health['status'] === 'invalid') {
                throw new Problem($health['message'], 409);
            }
            $records = $response['data']['list'] ?? null;
            if ((string) ($response['status'] ?? '') !== '1' || !is_array($records) || !array_is_list($records)) {
                throw new Problem('无法完整核对今天的乐跑记录，稍后重试', 502);
            }
            foreach ($records as $record) {
                $start = $record['start_time'] ?? null;
                $id = (string) ($record['id'] ?? '');
                if (!is_numeric($start) || $start > $previous || $id === '' || isset($seen[$id]) || (string) ($record['uid'] ?? '') !== $credentials['uid']) {
                    throw new Problem('成绩分页或账号信息不完整，请稍后重试', 502);
                }
                $seen[$id] = true;
                $previous = (int) $start;
                if (self::day((int) $start) < $day) {
                    return false;
                }
                if (self::day((int) $start) === $day && (string) ($record['type'] ?? '') === '1'
                    && (string) ($record['record_status'] ?? '') !== '0') {
                    return true;
                }
            }
            if (!$records) {
                return false;
            }
        }
        throw new Problem('今天的记录尚未核对完整，稍后重试', 502);
    }

    private function later(string $id, string $message, int $at): void
    {
        $this->app->db->execute('UPDATE hosting SET next_at=?,message=? WHERE account_id=?', [$at, $message, $id]);
    }

    private function update(string $id, string $state, string $message, int $count): void
    {
        $this->app->db->execute('UPDATE hosting SET state=?,message=?,completed=? WHERE account_id=?', [$state, $message, $count, $id]);
    }
}
