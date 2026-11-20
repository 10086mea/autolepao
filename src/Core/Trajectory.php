<?php

declare(strict_types=1);

namespace AutoLepao\Core;

/** Version 2 geometry and timeline, shared by previews, datasets and execution. */
final class Trajectory
{
    public static function circuit(array $c): array
    {
        $controls = $c['control_points'] ?? [];
        if (count($controls) < 2) {
            throw new Problem('请先画出路线');
        }
        // Follow the drawn segments exactly. Open paths return along the same line.
        $ring = $controls;
        if (empty($c['closed'])) {
            $ring = array_merge($ring, array_reverse(array_slice($controls, 1, -1)));
        }
        $ring[] = $ring[0];
        $lengths = [0.0];
        for ($i = 1;$i < count($ring);$i++) {
            $lengths[] = $lengths[$i - 1] + Rules::distance($ring[$i - 1], $ring[$i]);
        }
        if (end($lengths) < 1) {
            throw new Problem('路线长度不足');
        }
        return [$ring,$lengths];
    }

    private static function position(array $ring, array $lengths, float $distance): array
    {
        $lap = $lengths[count($lengths) - 1];
        $offset = fmod(fmod($distance, $lap) + $lap, $lap);
        $lo = 1;
        $hi = count($lengths) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($lengths[$mid] < $offset) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        $fraction = ($offset - $lengths[$lo - 1]) / max(1e-12, $lengths[$lo] - $lengths[$lo - 1]);
        return ['a' => $ring[$lo - 1]['a'] + ($ring[$lo]['a'] - $ring[$lo - 1]['a']) * $fraction,
            'o' => $ring[$lo - 1]['o'] + ($ring[$lo]['o'] - $ring[$lo - 1]['o']) * $fraction];
    }

    public static function generate(array $c, int $duration): array
    {
        $total = (float)$c['distance_km'] * 1000;
        $spacing = (float)$c['sample_m'];
        $min = (float)($c['speed_min_m_s'] ?? 1.5);
        $max = (float)($c['speed_max_m_s'] ?? 4.5);
        if ($duration <= 0 || $spacing <= 0 || $total <= 0 || $total / $spacing > 25000) {
            throw new Problem('轨迹时长、里程或采样参数无效');
        }
        $mean = $total / $duration;
        if ($mean < $min - 1e-9 || $mean > $max + 1e-9) {
            throw new Problem('目标时长与速度上下限不兼容，请调整配速');
        }
        // Local deterministic PRNG: no global random state and no PHP 8.2 dependency.
        $seed = (int)($c['trajectory_seed'] ?? 1);
        $counter = 0;
        $random = static function () use ($seed, &$counter): float {
            return hexdec(substr(hash('sha256', $seed.':'.$counter++), 0, 8)) / 4294967295;
        };
        $phases = [];
        for ($i = 0;$i < 6;$i++) {
            $phases[] = $random() * 2 * M_PI;
        }
        [$ring,$lengths] = self::circuit($c);
        $jitter = (float)$c['jitter_m'];
        $lat = array_sum(array_column($ring, 'a')) / count($ring);
        $mx = 111320 * cos(deg2rad($lat));
        $corridor = (float)($c['track_corridor_m'] ?? 0);
        $area = 0.0;
        for ($i = 1;$i < count($ring);$i++) {
            $area += ($ring[$i - 1]['o'] - $ring[0]['o']) * ($ring[$i]['a'] - $ring[0]['a']) - ($ring[$i]['o'] - $ring[0]['o']) * ($ring[$i - 1]['a'] - $ring[0]['a']);
        }
        $orientation = $area >= 0 ? 1 : -1;
        $gps = (float)($c['gps_drift_m'] ?? 0);
        $drift = [];
        if ($gps > 0) {
            // Correlated, irregularly spaced positioning errors, not independent
            // point jitter. Occasional larger anchors recover over several samples.
            $at = 0.0;
            $normal = (2 * $random() - 1) * $gps;
            $along = (2 * $random() - 1) * $gps * .5;
            while ($at < $total * 2 + 2000) {
                $drift[] = ['d' => $at,'n' => $normal,'t' => $along];
                $at += 18 + 42 * $random();
                $amplitude = $random() < .12 ? 1.5 : 1.0;
                $normal = .35 * $normal + .65 * (2 * $random() - 1) * $gps * $amplitude;
                $along = .5 * $along + .5 * (2 * $random() - 1) * $gps * .5;
            }
            $drift[] = ['d' => $at,'n' => $normal,'t' => $along];
        }
        $position = static function (float $d) use ($ring, $lengths, $jitter, $phases, $mx, $corridor, $total, $orientation, $gps, $drift): array {
            $p = self::position($ring, $lengths, $d);
            if ($corridor > 0) {
                $before = self::position($ring, $lengths, $d - 1);
                $after = self::position($ring, $lengths, $d + 1);
                $east = ($after['o'] - $before['o']) * $mx;
                $north = ($after['a'] - $before['a']) * 111320;
                $norm = hypot($east, $north);
                if ($norm < 1e-6) {
                    throw new Problem('跑道方向无法确定，请检查路线控制点');
                }
                // Strictly increasing, bounded lateral offset. No jump at lap joins,
                // no independent latitude/longitude noise, no repeated parallel lap.
                $offset = $corridor * (1 - 2 * exp(-$d / $total)) * $orientation;
                $along = 0.0;
                if ($gps > 0) {
                    $lo = 0;
                    $hi = count($drift) - 2;
                    while ($lo < $hi) {
                        $mid = intdiv($lo + $hi + 1, 2);
                        if ($drift[$mid]['d'] <= $d) {
                            $lo = $mid;
                        } else {
                            $hi = $mid - 1;
                        }
                    }
                    $a = $drift[$lo];
                    $b = $drift[$lo + 1];
                    $f = max(0, min(1, ($d - $a['d']) / ($b['d'] - $a['d'])));
                    $f = $f * $f * (3 - 2 * $f);
                    $offset = ($a['n'] + ($b['n'] - $a['n']) * $f) * $orientation;
                    $along = $a['t'] + ($b['t'] - $a['t']) * $f;
                }
                $p['o'] += ($north * $offset + $east * $along) / $norm / $mx;
                $p['a'] += (-$east * $offset + $north * $along) / $norm / 111320;
                return $p;
            }
            // Smooth correlated drift in metres, continuous across lap boundaries.
            foreach (['a','o'] as $axis => $key) {
                $noise = 0.0;
                foreach ([[35,.5],[90,.3],[220,.2]] as $k => [$wave,$weight]) {
                    $noise += $weight * sin(2 * M_PI * $d / $wave + $phases[$axis * 3 + $k]);
                }
                $p[$key] += $jitter / sqrt(2) * $noise / ($key === 'a' ? 111320 : $mx);
                $p[$key] = round($p[$key], 8);
            }
            if (abs($p['a']) > 85 || abs($p['o']) > 180) {
                throw new Problem('轨迹超出支持的坐标范围');
            }
            return $p;
        };
        $points = [$position(0) + ['distance_m' => 0.0]];
        $distance = 0.0;
        $base = 0.0;
        // Sub-micrometre interpolation residue must not create a duplicate finish fix.
        while ($distance < $total - 1e-5) {
            if (count($points) > 30000) {
                throw new Problem('轨迹采样过密，请减小扰动或增大采样间隔');
            }
            $base += $spacing;
            $previous = $points[count($points) - 1];
            $next = $position($base);
            $step = Rules::distance($previous, $next);
            if ($step < 1e-6) {
                throw new Problem('路线存在退化采样，请调整控制点或采样间隔');
            }
            if ($distance + $step > $total) {
                $f = ($total - $distance) / $step;
                foreach (['a','o'] as $key) {
                    $next[$key] = $previous[$key] + ($next[$key] - $previous[$key]) * $f;
                }
                $step = Rules::distance($previous, $next);
            }
            $distance += $step;
            $points[] = $next + ['distance_m' => $distance];
        }
        // Targets vary over distance intervals with zero-slope joins, not per-point noise.
        $segment = (float)($c['speed_segment_m'] ?? 100);
        $variation = (float)($c['speed_variation'] ?? .15);
        $targets = [];
        for ($i = 0;$i <= (int)ceil($distance / $segment) + 1;$i++) {
            $targets[] = $mean * (1 + $variation * (2 * $random() - 1));
        }
        $raw = [];
        $steps = [];
        for ($i = 1;$i < count($points);$i++) {
            $steps[$i] = $points[$i]['distance_m'] - $points[$i - 1]['distance_m'];
            $x = ($points[$i]['distance_m'] + $points[$i - 1]['distance_m']) / 2 / $segment;
            $k = (int)floor($x);
            $f = $x - $k;
            $f = $f * $f * (3 - 2 * $f);
            $raw[$i] = $targets[$k] + ($targets[$k + 1] - $targets[$k]) * $f;
        }
        // Solve within bounds; scaling and then clipping would break the target duration.
        $lo = .001;
        $hi = 1000.0;
        for ($iteration = 0;$iteration < 70;$iteration++) {
            $scale = ($lo + $hi) / 2;
            $elapsed = 0.0;
            foreach ($steps as $i => $step) {
                $elapsed += $step / max($min, min($max, $raw[$i] * $scale));
            }
            if ($elapsed > $duration) {
                $lo = $scale;
            } else {
                $hi = $scale;
            }
        }
        $elapsed = 0.0;
        $points[0] += ['elapsed' => 0.0,'s' => 0.0,'l' => 1];
        $markDistance = 0.0;
        $markTime = 0.0;
        $nextKm = 1000;
        foreach ($steps as $i => $step) {
            $speed = max($min, min($max, $raw[$i] * ($lo + $hi) / 2));
            $before = $elapsed;
            $elapsed += $step / $speed;
            $points[$i] += ['elapsed' => $elapsed,'s' => $speed,'l' => 1];
            if ($points[$i]['distance_m'] >= $nextKm - 1e-5) {
                $hit = $before + ($nextKm - $points[$i - 1]['distance_m']) / $speed;
                $points[$i]['b'] = 1.0;
                $points[$i]['c'] = (int)round($hit - $markTime);
                $markDistance = $nextKm;
                $markTime = $hit;
                $nextKm += 1000;
            }
        }
        $last = count($points) - 1;
        $points[0]['s'] = $points[1]['s'];
        $points[$last - 1]['p'] = 1;
        $remainder = $distance - $markDistance;
        if ($remainder > 1e-4) {
            $points[$last]['b'] = $remainder / 1000;
            $points[$last]['c'] = (int)round(($elapsed - $markTime) * 1000 / $remainder);
        }
        return $points;
    }
}
