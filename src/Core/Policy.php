<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Policy
{
    public function __construct(private Lepao $lepao)
    {
    }
    public function fetch(array $credentials, array $config, bool $activateZone = false): array
    {
        $r = $this->lepao->call('Run2/beforeRunV260', $credentials, ['game_id' => (string)$config['game_id'],'school_id' => (string)$config['school_id']]);
        $health = Health::lepao($r);
        if ($health['status'] === 'invalid') {
            throw new Problem($health['message'], 409);
        }
        [$zone,$default] = self::selection($r, $config);
        $detail = null;
        if ($zone !== $default && $activateZone) {
            $changed = $this->lepao->call('Run/setRunZone', $credentials, ['run_zone_id' => $zone]);
            if ((string)($changed['status'] ?? '') !== '1') {
                throw new Problem('切换跑区失败，本次不提交');
            }
            $r = $this->lepao->call('Run2/beforeRunV260', $credentials);
            [$zone,$default] = self::selection($r, $config);
            if ($zone !== $default) {
                throw new Problem('服务器未确认所选跑区，本次不提交');
            }
        }
        if ($zone !== $default) {
            $detail = $this->lepao->call('Run/GetZonePoints', $credentials, ['zone_id' => $zone,'school_id' => (string)$config['school_id']]);
        }
        return self::parse($r, $config, $detail);
    }
    public function overview(array $credentials): array
    {
        $response = $this->lepao->call('Run2/beforeRunV260', $credentials);
        $health = Health::lepao($response);
        if ($health['status'] === 'invalid') {
            throw new Problem($health['message'], 409);
        }
        if ((string) ($response['status'] ?? '') !== '1' || !is_array($response['data'] ?? null)) {
            throw new Problem('暂时无法读取跑区和学期，请稍后重试', 502);
        }
        $data = $response['data'];
        return ['zones' => $data['run_zone_list'] ?? [],
            'default_zone' => (string) ($data['run_zone_id'] ?? $data['game_id'] ?? ''),
            'semester' => Semester::parse($response)];
    }
    private static function selection(array $r, array $c): array
    {
        if ((string)($r['status'] ?? '') !== '1' || !is_array($r['data'] ?? null)) {
            throw new Problem('跑前接口未允许此账号，请检查认证状态或重新扫码', 409);
        }
        $d = $r['data'];
        if ((string)($d['school_id'] ?? '') !== (string)$c['school_id']) {
            throw new Problem('服务器返回的学校与路线配置不一致');
        }
        $default = (string)($d['run_zone_id'] ?? $d['game_id'] ?? '');
        $zone = (string)$c['game_id'];
        $selected = null;
        foreach ($d['run_zone_list'] ?? [] as $z) {
            if ((string)($z['id'] ?? '') === $zone) {
                $selected = $z;
            }
        }
        if ((isset($d['run_zone_list']) || $zone !== $default) && !$selected) {
            throw new Problem("所选跑区 $zone 不在服务器可选列表中");
        }
        return [$zone,$default,$selected];
    }
    public static function parse(array $r, array $c, ?array $detail = null): array
    {
        [$zone,$default,$selected] = self::selection($r, $c);
        $d = $r['data'];
        $line = $d['run_line_info'] ?? [];
        if ($zone !== $default) {
            if ((string)($detail['status'] ?? '') !== '1' || (string)($detail['data']['zone_id'] ?? '') !== $zone || empty($detail['data']['point_list'])) {
                throw new Problem('无法读取所选跑区打卡点，本次不执行');
            }
            $line['point_list'] = [];
            foreach ($detail['data']['point_list'] as $p) {
                if ((string)($p['status'] ?? '1') === '1') {
                    $line['point_list'][] = ['id' => $p['point_id'],'address' => $p['name'],'jingwei' => $p['jingwei']];
                }
            }
            if (!$line['point_list']) {
                throw new Problem('所选跑区没有可用打卡点');
            }
        }
        try {
            $windows = [];
            foreach ($d['time_rule_arr'] ?? [] as $w) {
                $windows[] = ['start' => $w['start_time'],'end' => $w['end_time'],'min_distance' => $w['min_distance'],'max_distance' => $w['max_distance'],'fastest_pace' => $w['max_pace'],'slowest_pace' => $w['min_pace'],'min_checkpoints' => $w['min_log_num'] ?? $line['point_num'] ?? 0];
            }
            $points = [];
            foreach ($line['point_list'] ?? [] as $p) {
                [$lat,$lng] = explode(',', $p['jingwei']);
                $points[] = ['id' => (string)$p['id'],'name' => $p['address'],'lat' => $lat,'lng' => $lng];
            }
            $rules = Rules::rules(['windows' => $windows,'checkpoints' => $points,'checkpoint_radius_m' => $line['log_max_distance'],'run_check' => $d['run_check'] ?? [],'gyroscope_type' => $d['gyroscope_type'] ?? 0]);
            $layers = [[['start' => (int)Rules::number($d['run_start_time'], '开放时间', 0, 86399),'end' => (int)Rules::number($d['run_end_time'], '关闭时间', 0, 86400)]]];
            foreach ([$d['running_time'] ?? null,$selected['time'] ?? null] as $times) {
                if ($times !== null) {
                    if (!$times) {
                        throw new Problem('时段限制为空');
                    }$layer = [];
                    foreach ($times as $w) {
                        $layer[] = ['start' => (int)Rules::number($w['start_time'], '开始时间', 0, 86399),'end' => (int)Rules::number($w['end_time'], '结束时间', 0, 86400)];
                    }$layers[] = $layer;
                }
            }
            foreach ($layers as $layer) {
                foreach ($layer as $w) {
                    if ($w['start'] === $w['end']) {
                        throw new Problem('有效时段不明确');
                    }
                }
            }
            $weekdays = array_map('intval', $d['week_time'] ?? range(0, 6));
            if (!$weekdays || array_diff($weekdays, range(0, 6))) {
                throw new Problem('星期限制无效');
            }
            $start = (int)Rules::number($d['term_start_time'], '学期开始', 0, 9999999999);
            $end = (int)Rules::number($d['term_end_time'], '学期结束', 0, 9999999999);
            if ($start >= $end || !ctype_digit((string)$d['term_id'])) {
                throw new Problem('学期格式无效');
            }
        } catch (\Throwable $e) {
            throw new Problem('服务器规则格式不完整，无法确定有效时间，本次不执行');
        }
        $assignment = ['complete' => $zone === $default,'set_point_type' => (int)($d['set_point_type'] ?? 0),'repeat_num' => (int)($d['repeat_num'] ?? -1),'points' => [],'windows' => []];
        foreach (['point_min_distance' => 'min_m','point_max_distance1' => 'max1_m','point_max_distance2' => 'max2_m'] as $key => $target) {
            if (!isset($line[$key]) || !is_numeric($line[$key])) {
                $assignment['complete'] = false;
            }
            $assignment[$target] = (float)($line[$key] ?? 0) * 1000;
        }
        foreach ($line['point_list'] ?? [] as $point) {
            foreach (['type','point_type','is_online'] as $key) {
                if (!isset($point[$key])) {
                    $assignment['complete'] = false;
                }
            }
            [$lat,$lng] = explode(',', $point['jingwei']);
            $assignment['points'][] = ['id' => (string)$point['id'],'name' => $point['address'],'lat' => (float)$lat,'lng' => (float)$lng,'type' => (int)($point['type'] ?? 0),'point_type' => (int)($point['point_type'] ?? 0),'online' => (int)($point['is_online'] ?? 0)];
        }
        foreach ($d['time_rule_arr'] ?? [] as $w) {
            if (!isset($w['point_num_online'],$w['point_num_offline'])) {
                $assignment['complete'] = false;
            }
            $assignment['windows'][] = ['start' => (int)$w['start_time'],'end' => (int)$w['end_time'],'online' => (int)($w['point_num_online'] ?? 0),'offline' => (int)($w['point_num_offline'] ?? 0)];
        }
        return ['source' => 'Run2/beforeRunV260'.($zone !== $default ? ' + Run/GetZonePoints' : ''),'fetched_at' => microtime(true),'timezone' => 'Asia/Shanghai','game_id' => $zone,'default_game_id' => $default,'zone_name' => $selected['name'] ?? $d['run_zone_name'] ?? '','school_id' => (string)$d['school_id'],'term_id' => (string)$d['term_id'],'term_start' => $start,'term_end' => $end,'weekdays' => $weekdays,'rules' => $rules,'assignment' => $assignment,'time_limits' => $layers,'finish_margin_s' => Schedule::MARGIN];
    }
}
