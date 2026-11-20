<?php

declare(strict_types=1);

namespace AutoLepao\Core;

final class Semester
{
    public static function parse(array $response): array
    {
        $d = $response['data'] ?? [];
        if ((string)($response['status'] ?? '') !== '1' || !is_array($d)) {
            throw new Problem('暂时无法查询本学期次数，请稍后重试', 502);
        }
        foreach (['total_num','term_start_time','term_end_time'] as $key) {
            if (!isset($d[$key]) || !preg_match('/^\d+$/', (string)$d[$key])) {
                throw new Problem('乐跑未返回完整的学期统计，请稍后重试', 502);
            }
        }
        if (empty($d['term_id']) || (int)$d['term_start_time'] >= (int)$d['term_end_time']) {
            throw new Problem('乐跑返回的学期信息不完整', 502);
        }
        $target = isset($d['term_num']) && ctype_digit((string)$d['term_num']) && (int)$d['term_num'] > 0 && (int)($d['rule_status'] ?? 1) !== 0 ? (int)$d['term_num'] : null;
        return ['count' => (int)$d['total_num'],'target' => $target,'remaining' => $target === null ? null : max(0, $target - (int)$d['total_num']),'term_id' => (string)$d['term_id'],
            'term_start' => (int)$d['term_start_time'],'term_end' => (int)$d['term_end_time'],'checked_at' => microtime(true)];
    }
}
