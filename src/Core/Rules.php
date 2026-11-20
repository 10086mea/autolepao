<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Rules
{
    public static function number(mixed $v, string $name, float $low, float $high): float
    {
        if (is_bool($v) || !is_numeric($v) || !is_finite((float)$v) || (float)$v < $low || (float)$v > $high) {
            throw new Problem("$name 必须在 $low–$high 之间");
        }
        return (float)$v;
    }
    public static function normalize(array $input): array
    {
        $out = ['trajectory_version' => 2, 'coordinate_system' => 'gcj02', 'rule_source' => 'live'];
        foreach (['distance_km' => [.2, 100, 2.2], 'pace_s_per_km' => [60, 3600, 360],
            'sample_m' => [1, 100, 4.4]] as $key => [$min, $max, $default]) {
            $out[$key] = self::number($input[$key] ?? $default, $key, $min, $max);
        }
        $out += ['speed_min_m_s' => 1000 / 3600, 'speed_max_m_s' => 1000 / 60,
            'speed_variation' => .12, 'speed_segment_m' => 100, 'jitter_m' => 0,
            'track_corridor_m' => 0, 'gps_drift_m' => 0,
            'trajectory_seed' => random_int(0, 2147483647)];
        $out['trajectory_seed'] = (int) self::number($input['trajectory_seed'] ?? $out['trajectory_seed'], '种子', 0, 2147483647);
        $controls = $input['control_points'] ?? [];
        if (!is_array($controls) || !array_is_list($controls) || count($controls) < 2 || count($controls) > 512) {
            throw new Problem('请在地图上画出至少两个点，最多支持512个点');
        }
        $out['closed'] = ($input['closed'] ?? false) === true;
        $out['control_points'] = [];
        $length = 0;
        foreach ($controls as $p) {
            if (!is_array($p)) {
                throw new Problem('路线坐标格式无效');
            }
            $point = ['a' => self::number($p['a'] ?? null, '纬度', -85, 85),
                'o' => self::number($p['o'] ?? null, '经度', -180, 180)];
            if ($out['control_points']) {
                $gap = self::distance(end($out['control_points']), $point);
                if ($gap < .5 || $gap > 5000) {
                    throw new Problem('相邻画线点应相距0.5至5000米');
                }
                $length += $gap;
            }
            $out['control_points'][] = $point;
        }
        if ($out['closed']) {
            $length += self::distance(end($out['control_points']), $out['control_points'][0]);
        }
        if ($length < 20 || $length > 50000) {
            throw new Problem('画线路线长度应在20米到50公里之间');
        }
        if ($out['distance_km'] * 1000 / $out['sample_m'] > 25000) {
            throw new Problem('轨迹点过多，请增大采样间隔');
        }
        foreach (['school_id', 'term_id', 'game_id'] as $key) {
            $value = (string) ($input[$key] ?? '');
            if (!preg_match('/^[0-9]{1,16}$/D', $value)) {
                throw new Problem('请先读取账号的学校、学期和跑区');
            }
            $out[$key] = $value;
        }
        $out['rules'] = self::rules($input['rules'] ?? []);
        return $out;
    }
    public static function rules(array $rules): array
    {
        $windows = $rules['windows'] ?? [];
        if (!$windows || count($windows) > 20 || count($rules['checkpoints'] ?? []) > 100) {
            throw new Problem('规则时段或打卡点数量无效');
        }
        $normalized = [];
        foreach ($windows as $w) {
            $item = [];
            foreach (['start' => [0,86399],'end' => [0,86400],'min_distance' => [0,100],'max_distance' => [0,100],'fastest_pace' => [1,3600],'slowest_pace' => [1,3600],'min_checkpoints' => [0,100]] as $key => [$lo,$hi]) {
                $item[$key] = self::number($w[$key] ?? null, $key, $lo, $hi);
            }
            if ($item['start'] === $item['end'] || $item['min_distance'] > $item['max_distance'] || $item['fastest_pace'] > $item['slowest_pace'] || floor($item['min_checkpoints']) !== $item['min_checkpoints']) {
                throw new Problem('时段、配速或打卡数量范围无效');
            }
            $normalized[] = $item;
        }
        $points = [];
        foreach ($rules['checkpoints'] ?? [] as $p) {
            $points[] = ['id' => (string)$p['id'],'name' => (string)$p['name'],'lat' => self::number($p['lat'], 'lat', -85, 85),'lng' => self::number($p['lng'], 'lng', -180, 180)];
        }
        return ['windows' => $normalized,'checkpoints' => $points,'checkpoint_radius_m' => self::number($rules['checkpoint_radius_m'] ?? 100, '打卡半径', 1, 1000),'run_check' => $rules['run_check'] ?? [],'gyroscope_type' => (int)self::number($rules['gyroscope_type'] ?? 0, '陀螺仪配置', 0, 1)];
    }
    public static function distance(array $a, array $b): float
    {
        $la = deg2rad($a['a']);
        $lb = deg2rad($b['a']);
        $dy = $lb - $la;
        $dx = deg2rad($b['o'] - $a['o']);
        return 6371000 * 2 * asin(min(1, sqrt(sin($dy / 2) ** 2 + cos($la) * cos($lb) * sin($dx / 2) ** 2)));
    }
    public static function route(array $config): array
    {
        return Trajectory::generate($config, Schedule::duration($config));
    }
    public static function evaluate(array $c, array $route, ?float $at = null, ?int $remote = null): array
    {
        $date = (new \DateTimeImmutable('@'.(int)($at ?? time())))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $seconds = (int)$date->format('G') * 3600 + (int)$date->format('i') * 60 + (int)$date->format('s');
        $window = null;
        foreach ($c['rules']['windows'] as $w) {
            if (self::inside($w, $seconds)) {
                $window = $w;
                break;
            }
        }
        $checks = [['key' => 'time_window','label' => '跑步时段（北京时间）','status' => $window ? 'pass' : 'fail','actual' => $date->format(DATE_ATOM),'expected' => $c['rules']['windows']]];
        if ($window) {
            foreach ([['distance','里程 km',$c['distance_km'],$window['min_distance'],$window['max_distance']],['pace','配速 秒/km',$c['pace_s_per_km'],$window['fastest_pace'],$window['slowest_pace']]] as [$key,$label,$actual,$lo,$hi]) {
                $checks[] = compact('key', 'label', 'actual') + ['expected' => [$lo,$hi],'status' => $actual >= $lo && $actual <= $hi ? 'pass' : 'fail'];
            }
            $details = [];
            $reached = 0;
            foreach ($c['rules']['checkpoints'] as $p) {
                $nearest = INF;
                foreach ($route as $point) {
                    $nearest = min($nearest, self::distance($point, ['a' => $p['lat'],'o' => $p['lng']]));
                }
                $within = $nearest <= $c['rules']['checkpoint_radius_m'];
                $reached += (int)$within;
                $details[] = ['id' => $p['id'],'name' => $p['name'],'nearest_m' => is_finite($nearest) ? round($nearest, 2) : null,'within_radius' => $within];
            }
            $checks[] = ['key' => 'checkpoints','label' => '路线接近打卡点','status' => $route ? ($reached >= $window['min_checkpoints'] ? 'pass' : 'fail') : 'unknown','actual' => $route ? $reached : null,'expected' => $window['min_checkpoints'],'details' => $details];
        }
        $states = array_column($checks, 'status');
        $precheck = in_array('fail', $states, true) ? 'fail' : (in_array('unknown', $states, true) ? 'incomplete' : 'pass');
        $checks[] = ['key' => 'behavior','label' => '运动状态检测','status' => 'unknown','actual' => $c['rules']['run_check'],'expected' => '需要实际传感器数据和最新配置'];
        $checks[] = ['key' => 'server','label' => '服务器最终有效性','status' => $remote === null ? 'unknown' : ($remote === 1 ? 'pass' : 'fail'),'actual' => $remote,'expected' => 'record_status == 1'];
        return ['precheck' => $precheck,'server_validity' => $remote === null ? 'unknown' : ($remote === 1 ? 'valid' : 'invalid'),'rule_source' => $c['rule_source'] ?? 'live','evaluated_at' => $date->format(DATE_ATOM),'checks' => $checks,'limitations' => ['本地预检通过不代表服务器成绩有效']];
    }
    public static function inside(array $w, float $seconds): bool
    {
        return $w['start'] < $w['end'] ? ($seconds >= $w['start'] && $seconds <= $w['end']) : ($seconds >= $w['start'] || $seconds <= $w['end']);
    }
}
