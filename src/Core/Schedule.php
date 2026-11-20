<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Schedule
{
    public const MARGIN = 300;
    public static function duration(array $c): int
    {
        return (int)ceil(round($c['distance_km'] * $c['pace_s_per_km'], 6));
    }
    private static function ranges(array $windows, int $day): array
    {
        $out = [];
        foreach ([-1,0,1] as $offset) {
            foreach ($windows as $w) {
                $out[] = [$day + $offset * 86400 + $w['start'],$day + $offset * 86400 + $w['end'] + ($w['end'] < $w['start'] ? 86400 : 0)];
            }
        }return $out;
    }
    public static function intervals(array $p, array $c, float $earliest): \Generator
    {
        $earliest = max($earliest, $p['term_start']);
        $date = (new \DateTimeImmutable('@'.(int)$earliest))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->setTime(0, 0);
        $duration = self::duration($c);
        for ($offset = 0;$offset < 15;$offset++) {
            $anchor = $date->modify("+$offset days");
            $day = $anchor->getTimestamp();
            $possible = [];
            if (!in_array((int)$anchor->format('w'), $p['weekdays'], true)) {
                continue;
            }
            foreach ($p['rules']['windows'] as $w) {
                if ($c['distance_km'] < $w['min_distance'] || $c['distance_km'] > $w['max_distance'] || $c['pace_s_per_km'] < $w['fastest_pace'] || $c['pace_s_per_km'] > $w['slowest_pace']) {
                    continue;
                }
                $ranges = self::ranges([$w], $day);
                foreach ($p['time_limits'] as $limits) {
                    $next = [];
                    foreach ($ranges as [$a,$b]) {
                        foreach (self::ranges($limits, $day) as [$x,$y]) {
                            if (max($a, $x) < min($b, $y)) {
                                $next[] = [max($a, $x),min($b, $y)];
                            }
                        }
                    }$ranges = $next;
                }
                foreach ($ranges as [$start,$end]) {
                    $start = max($start, $earliest, $day);
                    $end = min($end, $day + 86400, $p['term_end']);
                    if ($start + $duration + self::MARGIN <= $end) {
                        $possible[] = [$start,$end];
                    }
                }
            }
            if ($possible) {
                usort($possible, fn ($a, $b) => $a[0] <=> $b[0]);
                yield $possible;
            }
        }
    }
    public static function choose(array $p, array $c, string $mode = 'random', ?float $requested = null, ?float $now = null): array
    {
        $now ??= microtime(true);
        $earliest = $mode === 'fixed' ? ($requested ?? 0) : $now;
        if ($mode === 'fixed' && $earliest < $now) {
            throw new Problem('预约开始时间已过，请选择未来的北京时间');
        }
        $duration = self::duration($c);
        foreach (self::intervals($p, $c, max($earliest, $now)) as $intervals) {
            if ($mode === 'fixed') {
                foreach ($intervals as [$start,$end]) {
                    if ($start == $earliest) {
                        return self::slot($start, $end, $duration);
                    }
                }
                throw new Problem('预约时间无法在有效时段内完成全程及提交，请调整开始时间');
            }
            if ($mode === 'earliest') {
                return self::slot($intervals[0][0], $intervals[0][1], $duration);
            }
            $ranges = [];
            foreach ($intervals as [$a,$b]) {
                $a = (int)ceil($a);
                $b = (int)floor($b - $duration - self::MARGIN);
                if ($a > $b) {
                    continue;
                }
                $last = count($ranges) - 1;
                if ($last >= 0 && $a <= $ranges[$last][1] + 1) {
                    $ranges[$last][1] = max($ranges[$last][1], $b);
                } else {
                    $ranges[] = [$a,$b];
                }
            }
            if (!$ranges) {
                continue;
            }
            $total = array_sum(array_map(fn ($r) => $r[1] - $r[0] + 1, $ranges));
            $choice = random_int(0, $total - 1);
            foreach ($ranges as [$a,$b]) {
                if ($choice <= $b - $a) {
                    $start = $a + $choice;
                    foreach ($intervals as [$first,$end]) {
                        if ($first <= $start && $start + $duration + self::MARGIN <= $end) {
                            return self::slot($start, $end, $duration);
                        }
                    }
                } $choice -= $b - $a + 1;
            }
        }
        throw new Problem('未来14天没有可完成当前路线的有效时段，请检查里程、配速、星期或学期配置');
    }
    private static function slot(float $start, float $end, int $duration): array
    {
        return ['start_at' => $start,'finish_at' => $start + $duration,'window_end' => $end,'duration_s' => $duration,'finish_margin_s' => self::MARGIN];
    }
}
