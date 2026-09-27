<?php

namespace TypechoPlugin\MemberGate;

use Typecho\Common;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 通知站长
 *
 * 以 Telegram 机器人为主渠道：申请一到就推一条消息过去，消息下面挂「通过 / 拒绝」两个
 * inline 按钮 —— 按钮带的是 URL，不是 callback，所以**不需要配 webhook、不需要站点能被外网访问**，
 * 点一下就是打开审核链接，浏览器里那一步由 Widget\Register 处理。
 *
 * 邮件是备选渠道（很多虚拟主机发不出信，所以默认关着）。两个渠道各自独立，
 * 一个失败不影响另一个，只要有一个成功就算通知到了。
 *
 * @package MemberGate
 */
class Notifier
{
    /** Telegram Bot API 根地址 */
    private const API = 'https://api.telegram.org/bot';

    /** 最近一次错误，给后台显示用 */
    private static string $lastError = '';

    /**
     * @return string
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * 装了 Telegram 渠道没有
     *
     * @param array $settings
     * @return bool
     */
    public static function hasTelegram(array $settings): bool
    {
        return trim((string) $settings['tgToken']) !== '' && trim((string) $settings['tgChat']) !== '';
    }

    /**
     * 装了邮件渠道没有
     *
     * @param array $settings
     * @return bool
     */
    public static function hasMail(array $settings): bool
    {
        return !empty(Mailer::adminRecipients($settings));
    }

    /**
     * 通知站长，有一个渠道成功就算成功
     *
     * @param array $row 申请记录
     * @param array $settings
     * @param string $approveUrl
     * @param string $rejectUrl
     * @return bool
     */
    public static function notifyAdmin(array $row, array $settings, string $approveUrl, string $rejectUrl): bool
    {
        $ok = false;
        $errors = [];

        if (self::hasTelegram($settings)) {
            if (self::telegram($settings, self::adminText($row), [
                [
                    ['text' => _t('✅ 通过'), 'url' => $approveUrl],
                    ['text' => _t('❌ 拒绝'), 'url' => $rejectUrl],
                ],
            ])) {
                $ok = true;
            } else {
                $errors[] = _t('Telegram：%s', self::$lastError);
            }
        }

        if (self::hasMail($settings)) {
            if (Mailer::send($settings, Mailer::adminRecipients($settings), self::adminSubject($row),
                self::adminHtml($row, $approveUrl, $rejectUrl))) {
                $ok = true;
            } else {
                $errors[] = _t('邮件：%s', Mailer::lastError());
            }
        }

        if (!$ok) {
            self::$lastError = empty($errors)
                ? _t('没有配置任何通知渠道')
                : implode('；', $errors);
        }

        return $ok;
    }

    /**
     * Telegram 消息正文
     *
     * @param array $row
     * @return string
     */
    private static function adminText(array $row): string
    {
        $reason = (string) $row['reason'];
        if (Common::strLen($reason) > 700) {
            $reason = Common::subStr($reason, 0, 700, '…');
        }

        // 用户数据一律过 escape()，<b> 这些是我们自己的标签，所以拼完直接发，
        // 不能再整体转义一遍（那样会把自己的标签也转掉）
        $lines = [
            '🆕 <b>新的注册申请</b>',
            '',
            '<b>用户名</b>：' . self::escape((string) $row['name']),
            '<b>邮箱</b>：' . self::escape((string) $row['mail']),
            '<b>注册原因</b>：' . self::escape($reason),
            '<b>IP</b>：' . self::escape((string) $row['ip']),
            '<b>时区</b>：' . self::escape(
                trim((string) $row['timezone']) !== '' ? (string) $row['timezone'] : _t('（浏览器没报）')
            ),
            '<b>浏览器</b>：' . self::escape((string) $row['agent']),
            '<b>来源</b>：' . self::escape((string) $row['referer']),
            '<b>提交时间</b>：' . Plugin::stamp((int) $row['created'], 'Y-m-d H:i:s'),
            '',
            '<i>点下面的按钮审核。链接点一次就作废，处理过再点会提示「已经处理过」。</i>',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param array $row
     * @return string
     */
    private static function adminSubject(array $row): string
    {
        return _t('[%s] 新的注册申请：%s', (string) \Widget\Options::alloc()->title, $row['name']);
    }

    /**
     * @param array $row
     * @param string $approveUrl
     * @param string $rejectUrl
     * @return string
     */
    private static function adminHtml(array $row, string $approveUrl, string $rejectUrl): string
    {
        $rows = [
            _t('用户名')   => $row['name'],
            _t('邮箱')     => $row['mail'],
            _t('注册原因') => $row['reason'],
            _t('IP')       => $row['ip'],
            _t('时区')     => trim((string) $row['timezone']) !== ''
                ? $row['timezone'] : _t('（浏览器没报）'),
            _t('浏览器')   => $row['agent'],
            _t('来源')     => $row['referer'],
            _t('提交时间') => Plugin::stamp((int) $row['created'], 'Y-m-d H:i:s'),
        ];

        $html = '<p>' . self::escape(_t('有人申请注册，请审核。')) . '</p>'
            . '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse">';

        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<td style="background:#f5f5f7;white-space:nowrap"><strong>'
                . self::escape((string) $label) . '</strong></td>'
                . '<td style="word-break:break-all">' . nl2br(self::escape((string) $value)) . '</td>'
                . '</tr>';
        }

        return $html . '</table><p style="margin-top:24px">'
            . self::button($approveUrl, _t('通过'), '#2f9e44') . '&nbsp;&nbsp;'
            . self::button($rejectUrl, _t('拒绝'), '#c92a2a') . '</p>';
    }

    /**
     * @param string $url
     * @param string $label
     * @param string $color
     * @return string
     */
    private static function button(string $url, string $label, string $color): string
    {
        return '<a href="' . self::escape($url) . '" style="display:inline-block;padding:10px 22px;'
            . 'border-radius:999px;background:' . $color . ';color:#fff;text-decoration:none;'
            . 'font-weight:600">' . self::escape($label) . '</a>';
    }

    // ---------------------------------------------------------------- Telegram

    /**
     * 发一条 Telegram 消息
     *
     * @param array $settings
     * @param string $text HTML 格式
     * @param array $keyboard inline_keyboard 的行列结构
     * @return bool
     */
    public static function telegram(array $settings, string $text, array $keyboard = []): bool
    {
        if (!self::hasTelegram($settings)) {
            self::$lastError = _t('Telegram 没配好（缺 bot token 或 chat id）');
            return false;
        }

        // 正文长度是有界的（原因切到 700 字 + 固定字段），不会撞上 4096 的上限，
        // 所以不做整体截断 —— 截断会把 <b> 标签劈成两半，反而被 Telegram 拒收
        $data = [
            'chat_id'                  => (string) $settings['tgChat'],
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ];

        if (!empty($keyboard)) {
            $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }

        $result = self::request(self::apiUrl($settings, 'sendMessage'), $data);

        return $result !== null && !empty($result['ok']);
    }

    /**
     * 拉 bot 最近收到的会话，用来找 chat id
     *
     * 自己给 bot 发一句话，然后点后台的「拉取最近会话」就能看到自己的 id 了 ——
     * 比让站长去翻 getUpdates 的 JSON 友好得多。
     *
     * @param array $settings
     * @return array 每项是 ['chat_id' => ..., 'title' => ...]
     */
    public static function discoverChats(array $settings): array
    {
        if (trim((string) $settings['tgToken']) === '') {
            self::$lastError = _t('还没填 bot token');
            return [];
        }

        $result = self::request(self::apiUrl($settings, 'getUpdates') . '?limit=100', null, false);
        if ($result === null || empty($result['ok'])) {
            return [];
        }

        $chats = [];
        foreach (($result['result'] ?? []) as $update) {
            $chat = $update['message']['chat']
                ?? $update['edited_message']['chat']
                ?? $update['channel_post']['chat']
                ?? null;

            if (empty($chat['id'])) {
                continue;
            }

            $title = $chat['title']
                ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))
                ?: ($chat['username'] ?? '');
            $type = $chat['type'] ?? 'private';

            $chats[(string) $chat['id']] = [
                'chat_id' => (string) $chat['id'],
                'title'   => trim($title) . '（' . $type . '）',
            ];
        }

        return array_values($chats);
    }

    /**
     * 查这个 bot 有没有挂 webhook
     *
     * Telegram 里 getUpdates 和 webhook 是互斥的：挂了 webhook 再调 getUpdates 会直接
     * 返回 "Conflict: can't use getUpdates method while webhook is active"。
     * 所以拉不到会话时得先问一句，才能告诉站长到底卡在哪。
     *
     * @param array $settings
     * @return array|null getWebhookInfo 的 result，取不到返回 null
     */
    public static function webhookInfo(array $settings): ?array
    {
        if (trim((string) $settings['tgToken']) === '') {
            return null;
        }

        $result = self::request(self::apiUrl($settings, 'getWebhookInfo'), null, false);

        if ($result === null || empty($result['ok']) || !is_array($result['result'] ?? null)) {
            return null;
        }

        return $result['result'];
    }

    /**
     * @param array $settings
     * @param string $method
     * @return string
     */
    private static function apiUrl(array $settings, string $method): string
    {
        return self::API . trim((string) $settings['tgToken']) . '/' . $method;
    }

    /**
     * 请求 Telegram，返回解码后的 JSON
     *
     * 优先 curl，没装 curl 就退回 file_get_contents —— 虚拟主机上两者总有一个能用。
     * 都不可用时返回 null 并留下 lastError。
     *
     * @param string $url
     * @param array|null $data POST 数据，null 表示 GET
     * @param bool $post
     * @return array|null
     */
    private static function request(string $url, ?array $data, bool $post = true)
    {
        self::$lastError = '';
        $body = $post ? http_build_query($data ?? []) : '';

        $raw = null;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            if ($post) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw = curl_exec($ch);
            $error = curl_error($ch);

            if ($raw === false) {
                self::$lastError = $error !== '' ? $error : _t('curl 请求失败');
                return null;
            }
        } elseif (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method'        => $post ? 'POST' : 'GET',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content'       => $body,
                    'timeout'       => 15,
                    'ignore_errors' => true,
                ],
            ]);

            $raw = @file_get_contents($url, false, $context);

            if ($raw === false) {
                self::$lastError = _t('连不上 api.telegram.org（curl 和 allow_url_fopen 都用不了，'
                    . '或者服务器出不了外网）');
                return null;
            }
        } else {
            self::$lastError = _t('服务器既没有 curl 也关掉了 allow_url_fopen，发不了 Telegram');
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            self::$lastError = _t('Telegram 返回的不是 JSON：%s', Common::subStr((string) $raw, 0, 120, '…'));
            return null;
        }

        if (empty($decoded['ok'])) {
            self::$lastError = (string) ($decoded['description'] ?? _t('Telegram 拒绝了这次请求'));
        }

        return $decoded;
    }

    /**
     * 转义 HTML 里的保留字符
     *
     * 页面上显示的文字走这个。已经拼好的 HTML 片段（比如 <b>）不能再过一遍，
     * 所以在 adminText() 里是按行拼、最后整体走 escapeText()。
     *
     * @param string $text
     * @return string
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

}
