<?php

declare(strict_types=1);

namespace AutoLepao;

use AutoLepao\Core\{Problem, Rules};

final class Route
{
    public static function load(string $file): array
    {
        $file = trim($file, " \t\n\r\"'");
        if (!is_file($file) || filesize($file) > 5 * 1024 * 1024) {
            throw new Problem('路线文件不存在或超过5MB');
        }
        $text = file_get_contents($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'gpx') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET);
            if (!$xml) {
                throw new Problem('GPX 文件格式不正确');
            }
            $segments = $xml->xpath('//*[local-name()="trkseg"]');
            if (count($segments) > 1) {
                throw new Problem('请导出一条连续的 GPX 路线');
            }
            $nodes = $xml->xpath('//*[local-name()="trkpt" or local-name()="rtept"]');
            $coordinates = array_map(fn ($p) => [(float) $p['lon'], (float) $p['lat']], $nodes);
        } elseif (in_array($extension, ['json','geojson'], true)) {
            $json = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            if (($json['type'] ?? '') === 'Feature') {
                $json = $json['geometry'];
            }
            if (($json['type'] ?? '') !== 'LineString') {
                throw new Problem('GeoJSON 需要包含一条 LineString 路线');
            }
            $coordinates = $json['coordinates'] ?? [];
        } else {
            throw new Problem('请选择 GPX 或 GeoJSON 路线文件');
        }
        if (!is_array($coordinates) || count($coordinates) < 2) {
            throw new Problem('路线至少需要两个坐标点');
        }
        $points = [];
        foreach ($coordinates as $pair) {
            if (!is_array($pair)) {
                throw new Problem('路线坐标格式无效');
            }
            $lng = Rules::number($pair[0] ?? null, '经度', -180, 180);
            $lat = Rules::number($pair[1] ?? null, '纬度', -85, 85);
            $point = self::gcj($lat, $lng);
            if (!$points || Rules::distance(end($points), $point) >= 1) {
                $points[] = $point;
            }
        }
        if (count($points) < 2) {
            throw new Problem('路线点过于集中');
        }
        $closed = count($points) > 2 && Rules::distance($points[0], end($points)) < 3;
        if ($closed) {
            array_pop($points);
        }
        if (count($points) > 512) {
            throw new Problem('路线超过512个点，请先在导出工具中简化路线');
        }
        return ['control_points' => $points, 'closed' => $closed, 'name' => basename($file)];
    }

    public static function gcj(float $lat, float $lng): array
    {
        if ($lng < 72.004 || $lng > 137.8347 || $lat < .8293 || $lat > 55.8271) {
            return ['a' => $lat, 'o' => $lng];
        }
        $x = $lng - 105;
        $y = $lat - 35;
        $dy = -100 + 2 * $x + 3 * $y + .2 * $y * $y + .1 * $x * $y + .2 * sqrt(abs($x));
        $dy += (20 * sin(6 * $x * M_PI) + 20 * sin(2 * $x * M_PI)) * 2 / 3;
        $dy += (20 * sin($y * M_PI) + 40 * sin($y / 3 * M_PI)) * 2 / 3;
        $dy += (160 * sin($y / 12 * M_PI) + 320 * sin($y * M_PI / 30)) * 2 / 3;
        $dx = 300 + $x + 2 * $y + .1 * $x * $x + .1 * $x * $y + .1 * sqrt(abs($x));
        $dx += (20 * sin(6 * $x * M_PI) + 20 * sin(2 * $x * M_PI)) * 2 / 3;
        $dx += (20 * sin($x * M_PI) + 40 * sin($x / 3 * M_PI)) * 2 / 3;
        $dx += (150 * sin($x / 12 * M_PI) + 300 * sin($x / 30 * M_PI)) * 2 / 3;
        $radians = deg2rad($lat);
        $magic = 1 - .006693421622965943 * sin($radians) ** 2;
        return ['a' => $lat + $dy * 180 / ((6378245 * (1 - .006693421622965943)) / ($magic * sqrt($magic)) * M_PI),
            'o' => $lng + $dx * 180 / (6378245 / sqrt($magic) * cos($radians) * M_PI)];
    }
}
