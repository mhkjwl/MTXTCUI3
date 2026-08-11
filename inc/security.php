<?php
/**
 * @file security.php
 * @description CC 攻击防护模块，基于真实 IP 的滑动窗口限流
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

// CC攻击防护模块 — 基于真实 IP 的滑动窗口限流（文件存储，不依赖 session）
// 关键：使用「非阻塞锁」，避免同一 IP 的并发请求（如后台 iframe 同时加载多个页面）
// 在锁上排队串行，导致页面加载缓慢。
if(!defined('IN_CRONLITE'))exit();

$cc_window       = 5;    // 时间窗口（秒）
$cc_max_requests = 60;   // 窗口内最大请求数（后台 iframe 会并发多请求，阈值放宽）
$cc_ban_seconds  = 60;   // 超限后封禁时长（秒）

// 直接取 REMOTE_ADDR，避免信任可伪造的 XFF 头
$cc_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

// 存储目录（系统临时目录，避免占用 web 可访问路径）
$cc_dir = sys_get_temp_dir() . '/eecms_cc';
if(!is_dir($cc_dir)) @mkdir($cc_dir, 0700, true);

if(is_dir($cc_dir) && is_writable($cc_dir)) {
    $cc_file = $cc_dir . '/' . md5($cc_ip) . '.json';
    $now = time();

    $fp = @fopen($cc_file, 'c+');
    if($fp !== false) {
        // 非阻塞锁：拿不到锁立即放行本次请求，绝不等待（杜绝并发串行）
        if(@flock($fp, LOCK_EX | LOCK_NB)) {
            $data = array('start' => $now, 'count' => 0, 'ban' => 0);
            $raw = stream_get_contents($fp);
            $saved = json_decode($raw, true);
            if(is_array($saved)) $data = array_merge($data, $saved);

            $banned = false;
            // 处于封禁期
            if(($data['ban'] ?? 0) > $now) {
                $banned = true;
            } else {
                // 窗口滑动
                if($now - ($data['start'] ?? $now) >= $cc_window) {
                    $data['start'] = $now;
                    $data['count'] = 1;
                } else {
                    $data['count'] = (int)($data['count'] ?? 0) + 1;
                }
                // 超限则封禁
                if($data['count'] > $cc_max_requests) {
                    $data['ban'] = $now + $cc_ban_seconds;
                    $data['count'] = 0;
                    $data['start'] = $now;
                    $banned = true;
                }
                // 写回
                @ftruncate($fp, 0);
                @rewind($fp);
                @fwrite($fp, json_encode($data));
            }

            @flock($fp, LOCK_UN);
            @fclose($fp);

            if($banned) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $cc_ban_seconds);
                exit('请求过于频繁，请稍后再试。');
            }
        } else {
            // 未拿到锁，说明同 IP 有并发请求正在处理，直接放行，不阻塞
            @fclose($fp);
        }
    }
}
