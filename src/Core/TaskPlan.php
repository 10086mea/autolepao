<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class TaskPlan
{
    public static function checkpoints(array $policy, array $config, float $at): array
    {
        $a = $policy['assignment'] ?? [];
        if (empty($a['points']) && !empty($a['windows'])) {
            $required = array_filter($a['windows'], fn ($window) => $window['online'] > 0 || $window['offline'] > 0);
            if (!$required) {
                return [];
            }
        }
        if (($a['complete'] ?? false) !== true || (int)($a['set_point_type'] ?? 0) !== 1 || (int)($a['repeat_num'] ?? -1) !== 0) {
            throw new Problem('打卡点分配规则不完整或暂不支持，本次不提交');
        }
        $local = (new \DateTimeImmutable('@'.(int)$at))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $seconds = (int)$local->format('H') * 3600 + (int)$local->format('i') * 60 + (int)$local->format('s');
        $counts = [];
        foreach ($a['windows'] as $w) {
            $in = $w['start'] < $w['end'] ? ($seconds >= $w['start'] && $seconds < $w['end']) : ($seconds >= $w['start'] || $seconds < $w['end']);
            if ($in) {
                $counts[] = [(int)$w['online'],(int)$w['offline']];
            }
        }
        if (count($counts) !== 1 || $counts[0][1] !== 0 || $counts[0][0] < 1) {
            throw new Problem('本次打卡数量不明确或需要现场扫码，本次不提交');
        }
        $count = $counts[0][0];
        $origin = Rules::route($config)[0];
        $min = $a['min_m'];
        $max = $a['max2_m'];
        $pool = [];
        $seen = [];
        foreach ($a['points'] as $p) {
            if (isset($seen[$p['id']])) {
                throw new Problem('服务器打卡点重复');
            }$seen[$p['id']] = true;
            if (!in_array($p['type'], [1,2], true) || !in_array($p['point_type'], [1,2], true)) {
                throw new Problem('打卡点类型无法确认');
            }
            if ($p['point_type'] !== 1 || ($p['type'] === 2 && $p['online'] === 0)) {
                continue;
            }
            $distance = Rules::distance($origin, ['a' => $p['lat'],'o' => $p['lng']]);
            if ($p['type'] !== 1 && $distance < $min) {
                continue;
            }
            $p['origin_distance'] = $distance;
            $pool[] = $p;
        }
        usort($pool, fn ($a, $b) => $a['origin_distance'] <=> $b['origin_distance']);
        if (count($pool) < $count || $pool[$count - 1]['origin_distance'] > $max) {
            throw new Problem('起点附近没有足够的有效打卡点');
        }
        $mandatory = array_values(array_filter($pool, fn ($p) => $p['type'] === 1));
        $chosen = [];
        if ($mandatory) {
            $online = array_values(array_filter($mandatory, fn ($p) => $p['online'] === 1));
            $mandatory = $online ?: $mandatory;
            $first = $mandatory[random_int(0, count($mandatory) - 1)];
            $chosen[] = $first;
            $rest = array_values(array_filter($pool, fn ($p) => $p['id'] !== $first['id']));
            $options = [];
            foreach ([$a['max1_m'],$max] as $radius) {
                $options = array_values(array_filter($rest, fn ($p) => $p['type'] === 1 || (Rules::distance(['a' => $first['lat'],'o' => $first['lng']], ['a' => $p['lat'],'o' => $p['lng']]) > $min && Rules::distance(['a' => $first['lat'],'o' => $first['lng']], ['a' => $p['lat'],'o' => $p['lng']]) < $radius)));
                if (count($options) >= $count - 1) {
                    break;
                }
            }
        } else {
            $options = $pool;
        }
        while (count($chosen) < $count) {
            if (!$options) {
                throw new Problem('无法分配足够的打卡点');
            }$i = random_int(0, count($options) - 1);
            $chosen[] = $options[$i];
            array_splice($options, $i, 1);
        }
        shuffle($chosen);
        return array_map(fn ($p) => array_intersect_key($p, array_flip(['id','name','lat','lng'])), $chosen);
    }
}
