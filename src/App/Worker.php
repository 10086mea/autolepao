<?php

declare(strict_types=1);

namespace AutoLepao\App;

use AutoLepao\Core\{Crypto, Problem, Rules, Runner, Schedule, TaskPlan};
use AutoLepao\Support\Wakeup;

final class Worker
{
    public function __construct(private readonly Application $app)
    {
    }

    public function run(): void
    {
        $lock = fopen($this->app->directory.'/worker.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('A worker is already running');
        }
        $socket = stream_socket_server(Wakeup::address(), $error, $message, STREAM_SERVER_BIND);
        if (!$socket) {
            throw new \RuntimeException('Cannot bind scheduler wakeup port');
        }
        stream_set_blocking($socket, false);
        $this->recover();
        fwrite(STDOUT, "Scheduler ready. Waiting for reservations.\n");
        while (true) {
            try {
                while ($this->tick()) { /* Drain due transitions before sleeping. */
                }
                (new Hosting($this->app))->tick();
                $next = $this->app->db->one("SELECT MIN(at) AS at FROM (SELECT due_at AS at FROM tasks WHERE state IN ('scheduled','running') UNION ALL SELECT next_at AS at FROM hosting WHERE state='active')");
                // A low-frequency rescan also covers a dropped notification or web restart.
                $seconds = $next['at'] === null ? 900 : max(1, min(900, (int) $next['at'] - time()));
            } catch (\Throwable $error) {
                error_log('Scheduler error '.get_class($error).' code='.$error->getCode());
                $seconds = 30;
            }
            $read = [$socket];
            $write = $except = [];
            if (stream_select($read, $write, $except, $seconds) > 0) {
                while (stream_socket_recvfrom($socket, 1024) !== false) {
                }
            }
        }
    }

    public function recover(): void
    {
        foreach ($this->app->db->all("SELECT id,state FROM tasks WHERE state IN ('preparing','submitting')") as $task) {
            $uncertain = $task['state'] === 'submitting';
            $this->app->db->transition(
                $task['id'],
                $uncertain ? 'uncertain' : 'failed',
                $uncertain ? '提交时进程中断，请先核对乐跑成绩，再决定是否重新预约' : '跑前准备中断，请重新预约'
            );
        }
    }

    public function tick(): bool
    {
        $task = $this->app->db->transaction(function () {
            $row = $this->app->db->one("SELECT * FROM tasks WHERE state IN ('scheduled','running') AND due_at<=? ORDER BY due_at LIMIT 1", [time()]);
            if (!$row) {
                return null;
            }
            $state = $row['state'] === 'running' ? 'submitting' : 'preparing';
            $this->app->db->execute('UPDATE tasks SET state=? WHERE id=? AND state=?', [$state, $row['id'], $row['state']]);
            return $row;
        });
        if (!$task) {
            return false;
        }
        try {
            if ($task['state'] === 'running') {
                $this->finish($task);
            } else {
                $this->prepare($task);
            }
        } catch (\Throwable $error) {
            $uncertain = $task['state'] === 'running';
            $message = $error instanceof Problem ? $error->getMessage() : '执行失败，请查看应用日志中的错误类型';
            if ($uncertain) {
                $message .= '；请先核对乐跑成绩，再决定是否重新预约';
            }
            $this->app->db->transition($task['id'], $uncertain ? 'uncertain' : 'failed', $message, ['context' => null]);
            error_log('Task error id='.$task['id'].' phase='.$task['state'].' class='.get_class($error).' code='.$error->getCode());
        }
        return true;
    }

    private function prepare(array $task): void
    {
        $db = $this->app->db;
        $db->event($task['id'], '读取账号资料与最新跑区规则');
        $config = json_decode($task['config'], true, 512, JSON_THROW_ON_ERROR);
        $credentials = $this->app->credentials($task['account_id']);
        if ($credentials['school_id'] !== $config['school_id']) {
            throw new Problem('账号的学校已改变，请重新画线预约');
        }
        $policy = $this->app->policy->fetch($credentials, $config, true);
        $priorPolicy = json_decode($task['policy'], true, 512, JSON_THROW_ON_ERROR);
        $config['term_id'] = $policy['term_id'];
        $now = time();
        if ($task['mode'] === 'fixed' && $now - (int) $task['due_at'] > 60) {
            throw new Problem('已错过预约时间，请重新预约');
        }
        try {
            Schedule::choose($policy, $config, 'fixed', $now, $now);
        } catch (Problem $error) {
            if ($task['mode'] === 'fixed') {
                throw $error;
            }
            $slot = Schedule::choose($policy, $config, $task['mode']);
            $db->transition(
                $task['id'],
                'scheduled',
                '当前有效时段不足，已顺延到 '.date('Y-m-d H:i:s', (int) $slot['start_at']),
                ['due_at' => (int) ceil($slot['start_at']), 'policy' => Crypto::json($policy)]
            );
            return;
        }
        if (Crypto::json($priorPolicy['assignment'] ?? null) !== Crypto::json($policy['assignment'])) {
            throw new Problem('打卡分配规则已改变，请重新预览路线');
        }
        $expectedCount = count(TaskPlan::checkpoints($policy, $config, $now));
        if ($expectedCount !== count($config['assigned_checkpoints'])) {
            throw new Problem('当前时段的打卡数量已改变，请重新预览路线');
        }
        $candidates = array_column($policy['rules']['checkpoints'], null, 'id');
        foreach ($config['assigned_checkpoints'] as $point) {
            if (!isset($candidates[$point['id']]) || $candidates[$point['id']]['lat'] != $point['lat']
                || $candidates[$point['id']]['lng'] != $point['lng']) {
                throw new Problem('跑区打卡点已改变，请重新预览路线');
            }
        }
        $policy['rules']['checkpoints'] = $config['assigned_checkpoints'];
        $config['rules'] = $policy['rules'];
        $route = Rules::route($config);
        $report = Rules::evaluate($config, $route, $now);
        if ($report['precheck'] !== 'pass') {
            throw new Problem('路线未通过最新跑区规则检查');
        }
        $credentials['submission_ip'] = $config['submission_ip'];
        $db->event($task['id'], '学校 '.$config['school_id'].' · 学期 '.$config['term_id'].' · 跑区 '.$config['game_id']);
        $context = $this->app->runner->prepare($config, $credentials, $policy);
        $db->transition(
            $task['id'],
            'running',
            '轨迹已上传，预计 '.date('H:i:s', $context['resume_at']).' 提交',
            ['context' => Crypto::json($context), 'started_at' => $context['start'],
                'due_at' => $context['resume_at'], 'config' => Crypto::json($config), 'policy' => Crypto::json($policy)]
        );
    }

    private function finish(array $task): void
    {
        $context = json_decode($task['context'], true, 512, JSON_THROW_ON_ERROR);
        $account = $this->app->db->account($task['account_id']);
        if ((string) ($context['uid'] ?? '') !== $account['uid']) {
            throw new Problem('任务账号不一致');
        }
        $credentials = json_decode($account['credentials'], true);
        if (!hash_equals($context['credentials']['access_token'], $credentials['access_token'])) {
            throw new Problem('任务开始后账号重新登录，旧会话已停止，请核对远端成绩');
        }
        $this->app->db->event($task['id'], '正在提交，等待乐跑响应');
        $response = $this->app->runner->finish($context);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $result = ['status' => $response['status'] ?? null,
            'record_id' => $data['id'] ?? $data['record_id'] ?? null,
            'record_status' => $data['record_status'] ?? null,
            'distance' => $data['distance'] ?? null, 'used_time' => $data['used_time'] ?? null];
        $accepted = (string) ($response['status'] ?? '') === '1';
        $message = $accepted ? '已收到乐跑响应' : '乐跑拒绝本次提交，请核对账号状态和远端记录';
        if ($accepted && (string) ($data['record_status'] ?? '') === '1') {
            $message = '已提交，乐跑判定有效';
        }
        if ($accepted && (string) ($data['record_status'] ?? '') === '0') {
            $message = '已提交，乐跑判定无效';
        }
        $this->app->db->transition(
            $task['id'],
            $accepted ? 'completed' : 'failed',
            $message,
            ['result' => Crypto::json($result), 'context' => null]
        );
    }
}
