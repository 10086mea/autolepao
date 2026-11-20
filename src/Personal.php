<?php

declare(strict_types=1);

namespace AutoLepao;

use AutoLepao\Core\{Crypto, Health, Lepao, Policy, Problem, Rules, Runner, Schedule, TaskPlan};
use AutoLepao\Support\{Diagnostics, Http, Runtime};

final class Personal
{
    public readonly Lepao $api;
    public readonly Policy $policy;
    private readonly Runner $runner;

    public function __construct(public readonly State $state, private readonly Http $http = new Http(), private readonly Runtime $clock = new Runtime())
    {
        $this->api = new Lepao($http, new Diagnostics());
        $this->policy = new Policy($this->api);
        $this->runner = new Runner($this->api, $http, $clock);
    }

    public function profile(): array
    {
        $account = $this->state->get('account');
        if (!$account) {
            throw new Problem('还没有登录，请先执行 login');
        }
        $account = array_merge($account, $this->api->profile($account));
        $this->state->set('account', $account);
        return $account;
    }

    public function plan(string $mode = 'earliest', ?float $requested = null): array
    {
        $account = $this->profile();
        $input = array_merge($this->state->get('route', []), $this->state->get('options', []));
        $input['school_id'] = $account['school_id'];
        $policy = $this->policy->fetch($account, $input, true);
        $input['rules'] = $policy['rules'];
        $input['term_id'] = $policy['term_id'];
        $config = Rules::normalize($input);
        $slot = Schedule::choose($policy, $config, $mode, $requested, $this->clock->now());
        $config['assigned_checkpoints'] = TaskPlan::checkpoints($policy, $config, $slot['start_at']);
        $config['rules']['checkpoints'] = $config['assigned_checkpoints'];
        $route = Rules::route($config);
        $report = Rules::evaluate($config, $route, $slot['start_at']);
        if ($report['precheck'] !== 'pass') {
            throw new Problem('路线、里程或配速不满足所选跑区，请执行 setup 调整');
        }
        Runner::checkpointLogs($route, $config, $config['assigned_checkpoints'], (int) $slot['start_at'], $slot['duration_s']);
        return compact('config', 'policy', 'slot');
    }

    public function begin(array $plan): void
    {
        if ($this->state->get('pending')) {
            throw new Problem('仍有未完成的任务，请先恢复或查询结果');
        }
        $account = $this->profile();
        $config = $plan['config'];
        $policy = $this->policy->fetch($account, $config, true);
        if (Crypto::json($plan['policy']['assignment']) !== Crypto::json($policy['assignment'])) {
            throw new Problem('打卡规则已改变，请重新运行');
        }
        $now = $this->clock->now();
        Schedule::choose($policy, $config, 'fixed', $now, $now);
        if (count(TaskPlan::checkpoints($policy, $config, $this->clock->now())) !== count($config['assigned_checkpoints'])) {
            throw new Problem('当前时段的打卡数量已改变，请重新运行');
        }
        $policy['rules']['checkpoints'] = $config['assigned_checkpoints'];
        $config['rules'] = $policy['rules'];
        if (Rules::evaluate($config, Rules::route($config), $this->clock->now())['precheck'] !== 'pass') {
            throw new Problem('路线未通过最新规则检查');
        }
        $account['submission_ip'] = $this->http->localIp();
        $this->state->set('pending', ['phase' => 'preparing', 'created_at' => (int) $this->clock->now()]);
        try {
            $context = $this->runner->prepare($config, $account, $policy);
            unset($context['route']);
            $this->state->set('pending', ['phase' => 'running', 'context' => $context]);
        } catch (\Throwable $error) {
            $this->state->set('pending', null);
            throw $error;
        }
    }

    public function finish(): array
    {
        $pending = $this->state->get('pending');
        if (($pending['phase'] ?? '') !== 'running') {
            throw new Problem('当前没有可恢复的时间线，请先查询状态');
        }
        $context = $pending['context'];
        if ($this->clock->now() < $context['resume_at']) {
            throw new Problem('尚未到提交时间');
        }
        $account = $this->state->get('account');
        if (($account['uid'] ?? null) !== $context['uid'] || !hash_equals($context['credentials']['access_token'], $account['access_token'] ?? '')) {
            throw new Problem('时间线开始后登录会话已改变，请先核对乐跑记录');
        }
        $this->state->set('pending', ['phase' => 'submitting', 'context' => $context]);
        try {
            $response = $this->runner->finish($context);
        } catch (\Throwable $error) {
            $this->state->set('pending', ['phase' => 'uncertain', 'context' => $context]);
            throw new Problem('提交结果暂未确认。请执行 status 核对，确认前不会再次提交');
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $result = ['status' => $response['status'] ?? null, 'record_id' => $data['id'] ?? $data['record_id'] ?? null,
            'record_status' => $data['record_status'] ?? null, 'start' => $context['start']];
        $this->state->set('last_result', $result);
        $this->state->set('pending', null);
        return $result;
    }

    public function recordsToday(?int $since = null): array
    {
        $account = $this->state->get('account');
        $midnight = $since ?? strtotime('today', (int) $this->clock->now());
        $records = [];
        $seen = [];
        $previous = PHP_INT_MAX;
        for ($page = 1; $page <= 20; $page++) {
            $response = $this->api->call('Run2/allRecordList', $account, ['page' => (string) $page, 'year' => date('Y', $midnight)]);
            $health = Health::lepao($response);
            if ($health['status'] === 'invalid') throw new Problem($health['message']);
            $list = $response['data']['list'] ?? null;
            if ((string) ($response['status'] ?? '') !== '1' || !is_array($list) || !array_is_list($list)) {
                throw new Problem('暂时无法核对今天的记录，请稍后重试');
            }
            foreach ($list as $record) {
                $id = (string) ($record['id'] ?? '');
                $at = (string) ($record['start_time'] ?? '');
                if ($id === '' || !ctype_digit($at) || !isset($record['type']) || !is_scalar($record['type']) || isset($seen[$id]) || (int) $at > $previous || (string) ($record['uid'] ?? '') !== $account['uid']) {
                    throw new Problem('远端记录不完整，请稍后重试');
                }
                $seen[$id] = true;
                $previous = (int) $at;
                if ((int) $at < $midnight) {
                    return $records;
                }
                if ((string) ($record['type'] ?? '') === '1') {
                    $records[] = $record;
                }
            }
            if (!$list) {
                return $records;
            }
        }
        throw new Problem('今天的记录尚未核对完整，请稍后重试');
    }

    public function reconcile(): bool
    {
        $pending = $this->state->get('pending');
        if (!$pending) {
            return true;
        }
        if ($pending['phase'] === 'preparing') {
            $this->state->set('pending', null);
            return true;
        }
        foreach ($this->recordsToday((int) $pending['context']['start']) as $record) {
            if ((int) $record['start_time'] === (int) $pending['context']['start']) {
                $this->state->set('last_result', ['status' => 1, 'record_id' => $record['id'], 'record_status' => $record['record_status'] ?? null, 'start' => (int) $record['start_time']]);
                $this->state->set('pending', null);
                return true;
            }
        }
        return false;
    }
}
