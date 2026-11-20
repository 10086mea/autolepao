<?php

declare(strict_types=1);

namespace AutoLepao\Core;

use AutoLepao\Support\{Http,Runtime};

final class Runner
{
    private const X8 = ['aB3dE9fG2hJkL5mN','PqR7sT8uV9wXyZ1','A2bC3dE4fG5hI6j','K7lM8nO9pQ0rS1t','U2vW3xY4zA5bC6d','E7fG8hI9jK0lM1n','O2pQ3rS4tU5vW6x','Y7zA8bC9dE0fG1h','I2jK3lM4nO5pQ6r','S7tU8vW9xY0zA1b'];
    public function __construct(private Lepao $lepao, private Http $http, private Runtime $clock = new Runtime())
    {
    }
    public static function steps(int $duration, int $steps): array
    {
        if ($duration <= 0 || $steps < 0) {
            throw new Problem('步数参数无效');
        }$out = [];
        for ($i = 0;$i < $duration;$i += 60) {
            $out[] = (int)($steps * min(60, $duration - $i) / $duration);
        }
        $out[count($out) - 1] += $steps - array_sum($out);
        return ['interval' => 60,'list' => $out];
    }
    public static function track(array $config, int $duration): array
    {
        return array_map(
            fn ($point) => array_intersect_key($point, array_flip(['a','o','s','l','p','b','c'])),
            Trajectory::generate($config, $duration)
        );
    }
    // 客户端在定位回调里按进入打卡半径的那次定位记录时间与坐标，每 3 次回调才检查一轮且一次只打一个点；
    // 上传快照里的 distance 取自停止跑步时的总里程。
    public static function checkpointLogs(array $points, array $config, array $checkpoints, int $start, int $duration): array
    {
        $spacing = (float)$config['sample_m'];
        $radius = (int)round($config['rules']['checkpoint_radius_m']);
        $distance = round((float)$config['distance_km'], 2);
        $modern = ($config['trajectory_version'] ?? 1) >= 2;
        if ($modern) {
            [, $lengths] = Trajectory::circuit($config);
            $lap = max(1, (int)ceil(end($lengths) / $spacing));
        } else {
            $major = (float)$config['semi_major_m'];
            $minor = (float)$config['semi_minor_m'];
            $lap = max(1, (int)ceil(M_PI * (3 * ($major + $minor) - sqrt((3 * $major + $minor) * ($major + 3 * $minor))) / max(1.0, $spacing)));
        }
        $times = [];
        $elapsed = 0.0;
        foreach ($points as $step => $point) {
            $segment = $modern ? ($step ? Rules::distance($points[$step - 1], $point) : 0) : $spacing;
            $elapsed += $segment / max(.1, (float)$point['s']);
            $times[$step] = $elapsed;
        }
        $logs = [];
        $from = 0;
        foreach ($checkpoints as $index => $checkpoint) {
            $hit = null;
            $first = null;
            foreach ($points as $step => $point) {
                if ($step < $from) {
                    continue;
                }
                if ($first !== null && $step > $first + $lap) {
                    break;
                }
                $gap = Rules::distance(['a' => $point['a'],'o' => $point['o']], ['a' => $checkpoint['lat'],'o' => $checkpoint['lng']]);
                if ($gap > $radius) {
                    continue;
                }
                if ($first === null) {
                    $first = $step;
                }
                if ($hit === null || $gap < $hit[1]) {
                    $hit = [$step,$gap];
                }
            }
            if ($hit === null) {
                throw new Problem('打卡点不在本次轨迹范围内，本次不提交');
            }
            $point = $points[$hit[0]];
            $logs[$index] = [
                'point_id' => self::pointId((string)$checkpoint['id']),
                'time' => $start + min($duration, (int)round($times[$hit[0]])),
                'latitude' => (float)$point['a'],
                'longtitude' => (float)$point['o'],
                'distance' => $distance,
            ];
            $from = $hit[0] + 3;
        }
        ksort($logs);
        return array_values($logs);
    }
    private static function pointId(string $id): int|string
    {
        return ctype_digit($id) ? (int)$id : $id;
    }
    public function prepare(array $config, array $credentials, array $policy): array
    {
        if (!isset($config['assigned_checkpoints']) || $config['assigned_checkpoints'] !== $policy['rules']['checkpoints']) {
            throw new Problem('本次打卡点尚未确认，本次不提交');
        }
        $config['rules'] = $policy['rules'];
        $config['term_id'] = $policy['term_id'];
        $now = $this->clock->now();
        $slot = Schedule::choose($policy, $config, 'fixed', $now, $now);
        $stamp = $this->lepao->call('Run/getTimestampV278', $credentials);
        $timeString = $stamp['data']['str'] ?? '';
        if ((string)($stamp['status'] ?? '') !== '1' || !$timeString) {
            throw new Problem('无法取得服务器运行时间戳', 502);
        }
        $start = (int)$this->clock->now();
        $duration = Schedule::duration($config);
        $total = $config['distance_km'] * 1000;
        $sts = $this->lepao->call('report/getOssSts', $credentials);
        if ((string)($sts['status'] ?? '') !== '1' || !is_array($sts['data'] ?? null)) {
            throw new Problem('无法获取轨迹上传授权', 502);
        }$sts = $sts['data'];
        $points = self::track($config, $duration);
        $logs = self::checkpointLogs($points, $config, $policy['rules']['checkpoints'], $start, $duration);
        $body = Crypto::encrypt('NEW_GAME', Crypto::json($points));
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $key = 'Public/Upload/file/run_record/'.$day.'/'.random_int(1, 999).'/'.self::uuid().'.txt';
        $this->upload($sts, $key, $body);
        $end = $start + $duration;
        $steps = (int)($total / .947);
        $uid = (int)$credentials['uid'];
        $params = [
            'school_id' => (string)$config['school_id'],'term_id' => (string)$config['term_id'],'game_id' => (string)$config['game_id'],'is_running_area_valid' => '1','log_data' => Crypto::json($logs),'file_img' => '1',
            'used_time' => (string)$duration,'start_time' => (string)$start,'end_time' => (string)$end,'distance' => number_format($config['distance_km'], 2, '.', ''),'step_num' => (string)$steps,'record_file' => $key,'record_img' => '','newMobileDeviceId' => '',
            'step_info' => Crypto::json(self::steps($duration, $steps)),'record_str' => md5($config['school_id'].$uid.$timeString.self::X8[$uid % 10]),'pass_total' => (string)count($points),'pass_effective_num' => (string)count($points),
            'ip' => $credentials['submission_ip'],'gee_token' => '','run_check_res' => Crypto::json([['uid' => (string)$uid,'plan' => 0,'inteval_result' => '0','inteval_value' => '0','is_stop' => 0,'is_cheat' => 0]]),'run_face_verify_id' => '1231231231231','is_timeout_zfb' => '0','timestamp' => (string)$end,
        ];
        return ['version' => 1,'uid' => (string)$credentials['uid'],
            'credentials' => $credentials,'params' => $params,'start' => $start,'resume_at' => $end + 1,'window_end' => $slot['window_end'],'route' => $points];
    }
    public function finish(array $context): array
    {
        if (($context['version'] ?? null) !== 1 || empty($context['credentials']) || empty($context['params'])) {
            throw new Problem('任务上下文不完整', 409);
        }
        if ($this->clock->now() < $context['resume_at']) {
            throw new Problem('任务尚未到结束时间', 409);
        }
        if ($this->clock->now() + 30 >= $context['window_end']) {
            throw new Problem('已超出有效提交时段', 409);
        }
        return $this->lepao->call('Run/stopRunV278', $context['credentials'], $context['params']);
    }
    private function upload(array $sts, string $key, string $body): void
    {
        $bucket = (string)($sts['bucket'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $bucket)) {
            throw new Problem('轨迹存储桶配置异常', 502);
        }
        foreach (['AccessKeyId','AccessKeySecret','SecurityToken'] as $k) {
            if (empty($sts[$k])) {
                throw new Problem('轨迹上传授权不完整', 502);
            }
        }
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $md5 = base64_encode(md5($body, true));
        $oss = ['x-oss-security-token' => $sts['SecurityToken']];
        if (!empty($sts['callback'])) {
            $oss['x-oss-callback'] = $sts['callback'];
        }ksort($oss);
        $canonical = '';
        foreach ($oss as $k => $v) {
            $canonical .= "$k:$v\n";
        }
        $signature = base64_encode(hash_hmac('sha1', "PUT\n$md5\ntext/plain\n$date\n$canonical/$bucket/$key", $sts['AccessKeySecret'], true));
        $headers = $oss + ['Date' => $date,'Content-Type' => 'text/plain','Content-MD5' => $md5,'Authorization' => 'OSS '.$sts['AccessKeyId'].':'.$signature];
        $result = $this->http->request('PUT', "https://$bucket.oss-cn-hangzhou.aliyuncs.com/$key", ['headers' => $headers,'body' => $body,'timeout' => 30]);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new Problem('轨迹上传失败', 502);
        }
    }
    private static function uuid(): string
    {
        $v = bin2hex(random_bytes(16));
        return substr($v, 0, 8).'-'.substr($v, 8, 4).'-4'.substr($v, 13, 3).'-'.dechex((hexdec($v[16]) & 3) | 8).substr($v, 17, 3).'-'.substr($v, 20);
    }
}
