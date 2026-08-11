<?php
/**
 * @file smtp_mailer.php
 * @description 轻量级 SMTP 邮件发送类，基于 fsockopen 实现，支持 SSL/TLS 与 AUTH LOGIN
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

/**
 * 轻量级 SMTP 邮件发送类
 * 使用 PHP 原生 fsockopen 实现，无需第三方依赖
 * 支持 SSL/TLS 加密、AUTH LOGIN 认证
 */
if(!defined('IN_CRONLITE'))exit();

class SmtpMailer {

    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption; // '', 'ssl', 'tls'
    private $timeout;
    private $socket;
    private $debug = false;

    /**
     * @param array $config SMTP 配置
     */
    public function __construct($config) {
        $this->host       = isset($config['smtp_host']) ? $config['smtp_host'] : '';
        $this->port       = isset($config['smtp_port']) ? intval($config['smtp_port']) : 25;
        $this->username   = isset($config['smtp_user']) ? $config['smtp_user'] : '';
        $this->password   = isset($config['smtp_pass']) ? ct_decrypt($config['smtp_pass']) : '';
        $this->encryption = isset($config['smtp_secure']) ? $config['smtp_secure'] : '';
        $this->timeout    = 15;
    }

    /**
     * 发送邮件
     * @param string $to      收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body    邮件正文(HTML)
     * @param string $fromEmail 发件人邮箱
     * @param string $fromName  发件人名称
     * @return array ['success' => bool, 'error' => string]
     */
    public function send($to, $subject, $body, $fromEmail = '', $fromName = '') {
        if(empty($this->host)) {
            return ['success' => false, 'error' => 'SMTP 主机未配置'];
        }
        if(empty($fromEmail)) $fromEmail = $this->username;

        // 连接服务器
        $remote = ($this->encryption === 'ssl') ? 'ssl://' . $this->host : $this->host;
        $this->socket = @fsockopen($remote, $this->port, $errno, $errstr, $this->timeout);
        if(!$this->socket) {
            return ['success' => false, 'error' => "连接 SMTP 服务器失败: {$errstr} ({$errno})"];
        }
        stream_set_timeout($this->socket, $this->timeout);

        // 读取欢迎消息
        $resp = $this->readResponse();
        if($resp['code'] !== 220) {
            $this->close();
            return ['success' => false, 'error' => "服务器未就绪: {$resp['raw']}"];
        }

        // EHLO
        $myHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $myHost = preg_replace('/[^a-zA-Z0-9._-]/', '', $myHost);
        if(empty($myHost)) $myHost = 'localhost';

        if(!$this->sendCommand("EHLO {$myHost}", 250)) {
            // 尝试 HELO
            if(!$this->sendCommand("HELO {$myHost}", 250)) {
                $this->close();
                return ['success' => false, 'error' => 'EHLO/HELO 失败'];
            }
        }

        // STARTTLS
        if($this->encryption === 'tls') {
            if(!$this->sendCommand("STARTTLS", 220)) {
                $this->close();
                return ['success' => false, 'error' => 'STARTTLS 失败'];
            }
            if(!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->close();
                return ['success' => false, 'error' => 'TLS 加密握手失败'];
            }
            // 再次 EHLO
            if(!$this->sendCommand("EHLO {$myHost}", 250)) {
                $this->close();
                return ['success' => false, 'error' => 'TLS 后 EHLO 失败'];
            }
        }

        // 认证
        if(!empty($this->username)) {
            if(!$this->sendCommand("AUTH LOGIN", 334)) {
                $this->close();
                return ['success' => false, 'error' => 'AUTH LOGIN 请求失败'];
            }
            if(!$this->sendCommand(base64_encode($this->username), 334)) {
                $this->close();
                return ['success' => false, 'error' => '用户名认证失败'];
            }
            if(!$this->sendCommand(base64_encode($this->password), 235)) {
                $this->close();
                return ['success' => false, 'error' => '密码认证失败，请检查 SMTP 用户名和密码'];
            }
        }

        // MAIL FROM
        if(!$this->sendCommand("MAIL FROM: <{$fromEmail}>", 250)) {
            $this->close();
            return ['success' => false, 'error' => 'MAIL FROM 失败'];
        }

        // RCPT TO
        if(!$this->sendCommand("RCPT TO: <{$to}>", 250)) {
            $this->close();
            return ['success' => false, 'error' => 'RCPT TO 失败，邮箱地址可能无效'];
        }

        // DATA
        if(!$this->sendCommand("DATA", 354)) {
            $this->close();
            return ['success' => false, 'error' => 'DATA 命令失败'];
        }

        // 构建邮件内容
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $boundary = 'b' . md5(uniqid((string)time(), true));

        $headers = [];
        $headers[] = "From: {$encodedFromName} <{$fromEmail}>";
        $headers[] = "To: <{$to}>";
        $headers[] = "Subject: {$encodedSubject}";
        $headers[] = "Date: " . date('r');
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "Message-ID: <" . md5(uniqid((string)time(), true)) . "@" . $myHost . ">";

        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode(strip_tags($body))) . "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($body)) . "\r\n";
        $message .= "--{$boundary}--\r\n";

        // 发送邮件内容
        $message .= "\r\n.";
        if(!$this->sendCommand($message, 250)) {
            $this->close();
            return ['success' => false, 'error' => '邮件内容发送失败'];
        }

        // QUIT
        $this->sendCommand("QUIT", 221);
        $this->close();

        return ['success' => true, 'error' => ''];
    }

    /**
     * 发送 SMTP 命令并检查响应码
     */
    private function sendCommand($cmd, $expectedCode) {
        // 对 DATA 阶段的内容需要特殊处理点号
        if(substr($cmd, -2) === "\r\n.") {
            $data = $cmd;
        } else {
            $data = $cmd . "\r\n";
        }
        fwrite($this->socket, $data);

        $resp = $this->readResponse();
        if($resp['code'] !== $expectedCode) {
            if($this->debug) error_log("SMTP CMD [{$cmd}] => {$resp['raw']}");
            return false;
        }
        return true;
    }

    /**
     * 读取 SMTP 响应（支持多行）
     */
    private function readResponse() {
        $raw = '';
        $code = 0;
        while($line = fgets($this->socket, 515)) {
            $raw .= $line;
            if(isset($line[3]) && $line[3] === ' ') {
                $code = intval(substr($line, 0, 3));
                break;
            }
        }
        return ['code' => $code, 'raw' => trim($raw)];
    }

    /**
     * 关闭连接
     */
    private function close() {
        if($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}

/**
 * 便捷函数：发送验证码邮件
 * @param string $toEmail 收件人
 * @param string $code    验证码
 * @param string $siteName 站点名称
 * @return array ['success' => bool, 'error' => string]
 */
function send_verification_email($toEmail, $code, $siteName = '') {
    global $conf;

    $config = [
        'smtp_host'   => isset($conf['smtp_host']) ? $conf['smtp_host'] : '',
        'smtp_port'   => isset($conf['smtp_port']) ? $conf['smtp_port'] : 25,
        'smtp_user'   => isset($conf['smtp_user']) ? $conf['smtp_user'] : '',
        'smtp_pass'   => isset($conf['smtp_pass']) ? ct_decrypt($conf['smtp_pass']) : '',
        'smtp_secure' => isset($conf['smtp_secure']) ? $conf['smtp_secure'] : '',
    ];

    $fromEmail = isset($conf['smtp_from_email']) ? $conf['smtp_from_email'] : $config['smtp_user'];
    $fromName  = isset($conf['smtp_from_name']) ? $conf['smtp_from_name'] : ($siteName ?: '图床');

    $subject = "【{$siteName}】注册验证码";
    $body = '<!DOCTYPE html><html><body style="font-family:\'Microsoft YaHei\',sans-serif;max-width:480px;margin:0 auto;padding:20px;">'
          . '<div style="background:linear-gradient(135deg,#6d4aff,#ff4d9d);padding:24px;border-radius:12px 12px 0 0;text-align:center;">'
          . '<h1 style="color:#fff;margin:0;font-size:20px;">' . htmlspecialchars($siteName) . '</h1>'
          . '<p style="color:rgba(255,255,255,0.85);margin:6px 0 0;font-size:13px;">注册邮箱验证</p>'
          . '</div>'
          . '<div style="background:#fff;padding:28px 24px;border:1px solid #eee;border-top:none;border-radius:0 0 12px 12px;">'
          . '<p style="color:#333;font-size:14px;margin:0 0 16px;">您好，感谢您注册 ' . htmlspecialchars($siteName) . '。</p>'
          . '<p style="color:#333;font-size:14px;margin:0 0 20px;">您的验证码是：</p>'
          . '<div style="text-align:center;margin:20px 0;">'
          . '<span style="display:inline-block;font-size:32px;font-weight:bold;letter-spacing:8px;color:#6d4aff;background:#f5f3ff;padding:16px 32px;border-radius:8px;border:2px dashed #6d4aff;">' . htmlspecialchars($code) . '</span>'
          . '</div>'
          . '<p style="color:#999;font-size:12px;margin:16px 0 0;">验证码有效期为 10 分钟，请尽快使用。如非本人操作，请忽略此邮件。</p>'
          . '</div>'
          . '<p style="text-align:center;color:#ccc;font-size:11px;margin-top:16px;">此邮件由系统自动发送，请勿回复</p>'
          . '</body></html>';

    $mailer = new SmtpMailer($config);
    return $mailer->send($toEmail, $subject, $body, $fromEmail, $fromName);
}
