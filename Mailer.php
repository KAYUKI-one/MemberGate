<?php

namespace TypechoPlugin\MemberGate;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 发信
 *
 * Typecho 核心不带发信类（var/Typecho、var/Utils 里都没有），所以这里自己写。
 * 两种方式：SMTP（支持 ssl 直连和 STARTTLS）和 PHP 的 mail()。
 *
 * 发信失败**不能**把注册流程带崩 —— 全部返回 bool，出错只写日志，
 * 申请人已经提交的内容照常入库，站长可以在后台自己捞回来。
 *
 * @package MemberGate
 */
class Mailer
{
    /** 最近一次错误，给后台审核页显示用 */
    private static string $lastError = '';

    /**
     * @return string
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * 发一封 HTML 邮件
     *
     * @param array $settings 插件设置
     * @param string|array $to 收件人，可多个
     * @param string $subject 主题（纯文本，内部会做 MIME 编码）
     * @param string $html 正文
     * @return bool
     */
    public static function send(array $settings, $to, string $subject, string $html): bool
    {
        self::$lastError = '';

        $recipients = array_values(array_filter(
            array_map('trim', is_array($to) ? $to : [$to]),
            function ($mail) {
                return $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false;
            }
        ));

        if (empty($recipients)) {
            self::$lastError = '收件人为空或格式不对';
            return false;
        }

        $fromMail = (string) $settings['fromMail'];
        if ($fromMail === '' || filter_var($fromMail, FILTER_VALIDATE_EMAIL) === false) {
            $fromMail = $recipients[0];
        }
        $fromName = (string) $settings['fromName'];

        $headers = self::headers($fromMail, $fromName, $recipients, $subject);

        if ($settings['mailer'] === 'smtp') {
            return self::smtp($settings, $fromMail, $recipients, $headers, $html);
        }

        return self::phpMail($fromMail, $recipients, $headers, $html);
    }

    /**
     * MIME 头
     *
     * @param string $fromMail
     * @param string $fromName
     * @param array $recipients
     * @param string $subject
     * @return array
     */
    private static function headers(string $fromMail, string $fromName, array $recipients, string $subject): array
    {
        $from = $fromName === ''
            ? $fromMail
            : self::encodeWord($fromName) . ' <' . $fromMail . '>';

        return [
            'Date'         => date('r'),
            'From'         => $from,
            'To'           => implode(', ', $recipients),
            'Subject'      => self::encodeWord($subject),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            // base64 正文顺带解决了长行和 8bit 的问题，也天然不会被当成 SMTP 的结束点
            'Content-Transfer-Encoding' => 'base64',
        ];
    }

    /**
     * @param string $word
     * @return string
     */
    private static function encodeWord(string $word): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $word)) {
            return $word;
        }

        return '=?UTF-8?B?' . base64_encode($word) . '?=';
    }

    /**
     * PHP mail()
     *
     * @param string $fromMail
     * @param array $recipients
     * @param array $headers
     * @param string $html
     * @return bool
     */
    private static function phpMail(string $fromMail, array $recipients, array $headers, string $html): bool
    {
        if (!function_exists('mail')) {
            self::$lastError = '服务器禁用了 mail()';
            return false;
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $body = chunk_split(base64_encode($html));
        $ok = @mail(
            implode(', ', $recipients),
            $headers['Subject'],
            $body,
            implode("\r\n", $lines),
            '-f' . $fromMail
        );

        if (!$ok) {
            self::$lastError = 'mail() 返回失败，多半是服务器没配 MTA';
        }

        return $ok;
    }

    /**
     * SMTP
     *
     * @param array $settings
     * @param string $fromMail
     * @param array $recipients
     * @param array $headers
     * @param string $html
     * @return bool
     */
    private static function smtp(
        array $settings,
        string $fromMail,
        array $recipients,
        array $headers,
        string $html
    ): bool {
        if (!function_exists('stream_socket_client')) {
            self::$lastError = '服务器禁用了 stream_socket_client，无法用 SMTP';
            return false;
        }

        $host = (string) $settings['smtpHost'];
        $port = (int) $settings['smtpPort'];
        $user = (string) $settings['smtpUser'];
        $pass = (string) $settings['smtpPass'];
        $secure = (string) $settings['smtpSecure'];
        $timeout = 15;

        if ($host === '') {
            self::$lastError = 'SMTP 主机没填';
            return false;
        }
        if ($port <= 0) {
            $port = $secure === 'ssl' ? 465 : 25;
        }

        // 很多自建邮件服务器用的是自签证书，验证不过就整封发不出去，这里放宽
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';

        $fp = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$fp) {
            self::$lastError = '连不上 ' . $host . ':' . $port . ' —— ' . ($errstr !== '' ? $errstr : $errno);
            return false;
        }

        stream_set_timeout($fp, $timeout);

        $hostName = parse_url(\Widget\Options::alloc()->siteUrl, PHP_URL_HOST) ?: 'localhost';

        try {
            if (!self::expect($fp, [220])) {
                self::$lastError = 'SMTP 没有正常问候';
                return false;
            }

            if (!self::command($fp, 'EHLO ' . $hostName, [250])) {
                self::$lastError = 'EHLO 被拒绝';
                return false;
            }

            if ($secure === 'tls') {
                if (!self::command($fp, 'STARTTLS', [220])) {
                    self::$lastError = 'STARTTLS 被拒绝';
                    return false;
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    self::$lastError = 'TLS 握手失败';
                    return false;
                }
                if (!self::command($fp, 'EHLO ' . $hostName, [250])) {
                    self::$lastError = '加密后 EHLO 被拒绝';
                    return false;
                }
            }

            if ($user !== '') {
                if (!self::command($fp, 'AUTH LOGIN', [334])) {
                    self::$lastError = 'SMTP 不支持 AUTH LOGIN';
                    return false;
                }
                if (!self::command($fp, base64_encode($user), [334])) {
                    self::$lastError = 'SMTP 账号被拒绝';
                    return false;
                }
                if (!self::command($fp, base64_encode($pass), [235])) {
                    self::$lastError = 'SMTP 账号或密码不对';
                    return false;
                }
            }

            if (!self::command($fp, 'MAIL FROM:<' . $fromMail . '>', [250])) {
                self::$lastError = '发件地址被拒绝';
                return false;
            }

            foreach ($recipients as $recipient) {
                if (!self::command($fp, 'RCPT TO:<' . $recipient . '>', [250, 251])) {
                    self::$lastError = '收件地址被拒绝: ' . $recipient;
                    return false;
                }
            }

            if (!self::command($fp, 'DATA', [354])) {
                self::$lastError = 'DATA 被拒绝';
                return false;
            }

            $data = '';
            foreach ($headers as $name => $value) {
                $data .= $name . ': ' . $value . "\r\n";
            }
            $data .= "\r\n" . chunk_split(base64_encode($html), 76, "\r\n");

            fwrite($fp, $data . ".\r\n");

            if (!self::expect($fp, [250])) {
                self::$lastError = '服务器拒收了这封信';
                return false;
            }

            self::command($fp, 'QUIT', [221]);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            if (is_resource($fp)) {
                fclose($fp);
            }
            return false;
        }
    }

    /**
     * 发一条命令并断言返回码
     *
     * @param resource $fp
     * @param string $command
     * @param array $codes
     * @return bool
     */
    private static function command($fp, string $command, array $codes): bool
    {
        fwrite($fp, $command . "\r\n");
        return self::expect($fp, $codes);
    }

    /**
     * 读返回，直到收到多行回复的最后一行，再核对状态码
     *
     * @param resource $fp
     * @param array $codes
     * @return bool
     */
    private static function expect($fp, array $codes): bool
    {
        // 缓冲区给足：协议规定单行不超过 512 字节，但真实服务器的 EHLO 应答
        // （Gmail、QQ 会一口气列一堆扩展）经常更长，读半行会把状态码解析错
        while (($line = fgets($fp, 4096)) !== false) {
            if (strlen($line) < 4) {
                return false;
            }
            // 第 4 个字符是 '-' 说明这行后面还有，继续读
            if ($line[3] === '-') {
                continue;
            }

            return in_array((int) substr($line, 0, 3), $codes, true);
        }

        return false;
    }

    /**
     * 站长收件箱
     *
     * 设置里留空时退回到所有 administrator 的邮箱 —— 插件一装上就能用，
     * 不用先去填设置。
     *
     * @param array $settings
     * @return array
     */
    public static function adminRecipients(array $settings): array
    {
        $toMail = trim((string) $settings['toMail']);
        if ($toMail !== '') {
            return array_map('trim', preg_split('/[,;\s]+/', $toMail) ?: []);
        }

        $db = \Typecho\Db::get();
        $rows = $db->fetchAll(
            $db->select('mail')->from('table.users')
                ->where('group = ?', 'administrator')
                ->where("mail IS NOT NULL AND mail <> ''")
        );

        return array_column($rows, 'mail');
    }
}
