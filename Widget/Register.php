<?php

namespace TypechoPlugin\MemberGate\Widget;

use Throwable;
use Typecho\Common;
use Typecho\Cookie;
use Typecho\Db;
use Typecho\Validate;
use TypechoPlugin\MemberGate\Model;
use TypechoPlugin\MemberGate\Notifier;
use TypechoPlugin\MemberGate\Plugin;
use Utils\PasswordHash;
use Widget\Base;
use Widget\Notice;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 注册申请
 *
 * 一个入口几件事：
 *
 *   GET  /action/memberregister                        → 填申请表
 *   POST /action/memberregister                        → 存申请、推送给你
 *   GET  ?do=status&id&t                               → 申请人自查审核状态
 *   GET  ?do=review&id&act&token                       → Telegram 按钮 / 后台面板的审核
 *   GET  ?do=tgtest                                    → 发一条测试消息（要管理员）
 *   GET  ?do=tgdiscover                                → 拉 bot 最近会话，找 chat id（要管理员）
 *
 * 站在 /action/ 路由上（不是主题模板），所以任何主题都能用，也不用改主题文件。
 * 页面样式全部内联，不依赖主题的 CSS。
 *
 * @package MemberGate
 */
class Register extends Base implements \Widget\ActionInterface
{
    /** CSRF 用，和核心 Security 的 md5(secret & suffix) 一个套路 */
    private const CSRF_SALT = 'membergate-register';

    /** 注册原因长度上限 */
    private const REASON_MAX = 500;

    /** 审核备注长度上限 */
    private const NOTE_MAX = 255;

    /**
     * 入口
     */
    public function action()
    {
        $this->response->setContentType('text/html');

        $do = (string) $this->request->get('do');

        switch ($do) {
            // 故意不在这里要求管理员登录 —— Telegram 那个「通过」按钮就指望
            // 不登录也能点。放行条件由 review() 自己判：登录着的管理员，或者拿着一次性 token
            case 'review':
                $this->review();
                return;

            // 这两个是后台面板上的工具，注册开着关着都能进，但得是管理员
            case 'tgtest':
                if (!$this->requireAdmin()) {
                    return;
                }
                $this->telegramTest();
                return;
            case 'tgdiscover':
                if (!$this->requireAdmin()) {
                    return;
                }
                $this->telegramDiscover();
                return;

            // 自助查询：注册关了也得能用，不然申请人查不到结果
            case 'status':
                $this->status();
                return;
        }

        if (!$this->options->allowRegister || $this->user->hasLogin()) {
            $this->response->redirect($this->options->siteUrl);
        }

        if ($this->request->isPost()) {
            $this->submit();
        } else {
            $this->form();
        }
    }

    /**
     * tgtest / tgdiscover 只给管理员
     *
     * 不通过就直接输出一页并结束请求，所以调用方拿到 false 只需要 return。
     *
     * @return bool
     */
    private function requireAdmin(): bool
    {
        if ($this->user->hasLogin() && $this->user->pass('administrator', true)) {
            return true;
        }

        $this->page(_t('无权操作'), $this->alert(_t('请先用管理员账号登录后台。'), 'error'));
        return false;
    }

    // ---------------------------------------------------------------- 填表

    /**
     * 输出申请表
     *
     * @param array $values 回填值
     * @param array $errors 字段名 => 错误信息
     * @param string $fatal 整页级的错误（限流之类），有它就只显示这一条
     */
    private function form(array $values = [], array $errors = [], string $fatal = ''): void
    {
        $settings = Plugin::settings();

        $html = '';

        if ($fatal !== '') {
            $html .= $this->alert($fatal, 'error');
        }
        if (!empty($errors)) {
            $html .= $this->alert(implode('；', array_unique($errors)), 'error');
        }

        $intro = trim((string) $settings['formIntro']);
        if ($intro !== '') {
            $html .= '<p class="mg-intro">' . nl2br($this->escape($intro)) . '</p>';
        }

        $html .= '<form method="post" action="' . $this->escape(Plugin::registerUrl()) . '" class="mg-form">'
            . '<input type="hidden" name="_" value="' . $this->escape($this->csrfToken()) . '">'
            // 时区由下面的 JS 填。填不上也不影响提交，站长那边会显示「浏览器没报」
            . '<input type="hidden" name="tz" id="mg-tz" value="">';

        $html .= $this->field('name', _t('用户名'), 'text', (string) ($values['name'] ?? ''), $errors['name'] ?? '', [
            'autocomplete' => 'username',
            'required'     => 'required',
            'autofocus'    => 'autofocus',
        ]);
        $html .= $this->field('mail', _t('邮箱'), 'email', (string) ($values['mail'] ?? ''), $errors['mail'] ?? '', [
            'autocomplete' => 'email',
            'required'     => 'required',
        ]);
        $html .= $this->field('password', _t('密码'), 'password', '', $errors['password'] ?? '', [
            'autocomplete' => 'new-password',
            'required'     => 'required',
        ]);
        $html .= $this->field('confirm', _t('确认密码'), 'password', '', $errors['confirm'] ?? '', [
            'autocomplete' => 'new-password',
            'required'     => 'required',
        ]);

        $html .= '<label class="mg-field">'
            . '<span class="mg-label">' . $this->escape(_t('注册原因')) . '</span>'
            . '<textarea name="reason" rows="4" required placeholder="'
            . $this->escape(_t('想看图？想投稿？写上几句，站长好判断。')) . '">'
            . $this->escape((string) ($values['reason'] ?? '')) . '</textarea>'
            . (!empty($errors['reason']) ? '<em class="mg-error">' . $this->escape($errors['reason']) . '</em>' : '')
            . '</label>';

        $html .= '<button type="submit" class="mg-submit">' . $this->escape(_t('提交申请')) . '</button>';

        $html .= '<p class="mg-note">' . $this->escape(
            _t('提交后会给你一个「查询进度」的链接，记得收藏 —— 审核结果不讲邮件，靠它和登录提示。')
        ) . '</p>';

        $html .= '</form>';

        $html .= '<p class="mg-foot">'
            . '<a href="' . $this->escape($this->options->siteUrl) . '">' . $this->escape(_t('返回首页')) . '</a>'
            . '<span>·</span>'
            . '<a href="' . $this->escape($this->options->loginUrl) . '">'
            . $this->escape(_t('已有账号，去登录')) . '</a>'
            . '</p>';

        $html .= '<script>(function(){try{'
            . 'var z=Intl.DateTimeFormat().resolvedOptions().timeZone;'
            . 'if(z){document.getElementById("mg-tz").value=z;}'
            . '}catch(e){}})();</script>';

        $this->page($settings['formTitle'], $html);
    }

    /**
     * 单个输入框
     *
     * @param string $name
     * @param string $label
     * @param string $type
     * @param string $value
     * @param string $error
     * @param array $attrs
     * @return string
     */
    private function field(
        string $name,
        string $label,
        string $type,
        string $value,
        string $error,
        array $attrs = []
    ): string {
        $attr = '';
        foreach ($attrs as $key => $val) {
            $attr .= ' ' . $key . '="' . $this->escape($val) . '"';
        }

        return '<label class="mg-field">'
            . '<span class="mg-label">' . $this->escape($label) . '</span>'
            . '<input type="' . $this->escape($type) . '" name="' . $this->escape($name) . '"'
            . ' value="' . $this->escape($value) . '"' . $attr . '>'
            . ($error !== '' ? '<em class="mg-error">' . $this->escape($error) . '</em>' : '')
            . '</label>';
    }

    // ---------------------------------------------------------------- 提交

    /**
     * 处理提交
     */
    private function submit(): void
    {
        $settings = Plugin::settings();

        // CSRF。表单是公开的，但也不能让人替别人提交申请
        if (!hash_equals($this->csrfToken(), (string) $this->request->get('_'))) {
            $this->form([], [], _t('页面放了太久，请重新填一次。'));
            return;
        }

        $values = [
            'name'     => trim((string) $this->request->get('name')),
            'mail'     => trim((string) $this->request->get('mail')),
            'password' => (string) $this->request->get('password'),
            'confirm'  => (string) $this->request->get('confirm'),
            'reason'   => trim((string) $this->request->get('reason')),
        ];

        // 记住填过的内容，出错重填时不用再打一遍（和核心注册一样的做法）
        Cookie::set('__typecho_remember_name', $values['name']);
        Cookie::set('__typecho_remember_mail', $values['mail']);

        $errors = $this->validate($values);

        if (!empty($errors)) {
            $this->form($values, $errors);
            return;
        }

        $ip = $this->request->getIp();
        $max = (int) $settings['maxPendingPerIp'];

        // 建表失败、数据库抽风之类的问题，不能把申请人甩到一个 500 页面上
        try {
            if ($max > 0 && $ip !== '' && Model::pendingCountByIp($ip) >= $max) {
                $this->form($values, [], _t('这个 IP 上压着的待审申请太多了，等站长处理完再提交吧。'));
                return;
            }

            $row = [
                'name'      => $values['name'],
                'mail'      => $values['mail'],
                'password'  => (new PasswordHash(8, true))->hashPassword($values['password']),
                'reason'    => $values['reason'],
                'ip'        => $ip,
                'timezone'  => $this->timezone(),
                'agent'     => Common::subStr((string) $this->request->getAgent(), 0, 255, ''),
                'referer'   => Common::subStr((string) $this->request->getReferer(), 0, 255, ''),
                'status'    => Model::PENDING,
                'token'     => Model::newToken(),
                'mail_sent' => 0,
                'created'   => $this->options->time,
            ];

            $id = Model::create($row);
            $row['id'] = $id;

            // 通知站长。发失败不算提交失败 —— 申请已经在库里了，站长到后台能看到
            $notified = Notifier::notifyAdmin(
                $row,
                $settings,
                $this->reviewUrl($id, (string) $row['token'], 'approve'),
                $this->reviewUrl($id, (string) $row['token'], 'reject')
            );

            if ($notified) {
                Model::update($id, ['mail_sent' => 1]);
            }
        } catch (Throwable $e) {
            $this->form($values, [], _t('服务器这边出了点问题，申请没提交成功。稍后再试一次，'
                . '或者直接联系站长。'));
            return;
        }

        Cookie::delete('__typecho_remember_name');
        Cookie::delete('__typecho_remember_mail');

        $statusUrl = Plugin::statusUrl($row);

        $html = $this->alert(_t('申请已经提交，等站长审核'), 'success')
            . '<p class="mg-intro">' . $this->escape(
                _t('审核结果不会发邮件。这个链接是你的进度查询地址，收藏它，随时点开看进度。')
            ) . '</p>'
            . '<p class="mg-link"><a href="' . $this->escape($statusUrl) . '">'
            . $this->escape($statusUrl) . '</a></p>'
            . '<button type="button" class="mg-submit mg-copy" data-link="' . $this->escape($statusUrl) . '">'
            . $this->escape(_t('复制查询链接')) . '</button>'
            . '<p class="mg-note">' . $this->escape(
                _t('审核通过后，直接用刚才设置的用户名和密码登录就行。'
                    . '审核之前去登录会提示"还在审核中"，那是正常的，不是密码错了。')
            ) . '</p>';

        if (!$notified) {
            // 申请在库里，但站长收不到通知 —— 这个必须让申请人知道，好去别处找站长
            $html = $this->alert(
                _t('申请已经存下来了，但站长的通知没发出去（%s）。'
                    . '如果你认识站长，麻烦直接提醒他一声。', Notifier::lastError()),
                'error'
            ) . $html;
        }

        $html .= '<p class="mg-foot"><a href="' . $this->escape($this->options->siteUrl) . '">'
            . $this->escape(_t('返回首页')) . '</a></p>';

        // 翻译串要塞进 JS 字符串字面量里，用 json_encode 而不是 htmlspecialchars ——
        // 后者挡不住引号，一句带引号的翻译就能把整段脚本拆坏
        $copied = json_encode(_t('已复制'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $manual = json_encode(_t('手动复制这个链接：'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        $html .= '<script>(function(){var b=document.querySelector(".mg-copy");if(!b)return;'
            . 'b.addEventListener("click",function(){var t=b.getAttribute("data-link");'
            . 'var done=function(){b.textContent=' . $copied . ';};'
            . 'if(navigator.clipboard&&navigator.clipboard.writeText){'
            . 'navigator.clipboard.writeText(t).then(done,function(){});}'
            . 'else{window.prompt(' . $manual . ',t);}});})();</script>';

        $this->page(_t('申请已提交'), $html);
    }

    /**
     * 字段校验
     *
     * @param array $values
     * @return array 字段名 => 错误信息
     */
    private function validate(array $values): array
    {
        $validator = new Validate();
        $validator->addRule('name', 'required', _t('必须填写用户名称'));
        $validator->addRule('name', 'minLength', _t('用户名至少包含2个字符'), 2);
        $validator->addRule('name', 'maxLength', _t('用户名最多包含32个字符'), 32);
        $validator->addRule('name', 'xssCheck', _t('请不要在用户名中使用特殊字符'));
        $validator->addRule('mail', 'required', _t('必须填写电子邮箱'));
        $validator->addRule('mail', 'email', _t('电子邮箱格式错误'));
        $validator->addRule('mail', 'maxLength', _t('电子邮箱最多包含64个字符'), 64);
        $validator->addRule('password', 'required', _t('必须填写密码'));
        $validator->addRule('password', 'minLength', _t('为了保证账户安全, 请输入至少六位的密码'), 6);
        $validator->addRule('password', 'maxLength', _t('为了便于记忆, 密码长度请不要超过十八位'), 18);
        $validator->addRule('confirm', 'confirm', _t('两次输入的密码不一致'), 'password');
        $validator->addRule('reason', 'required', _t('请填写注册原因'));
        $validator->addRule('reason', 'maxLength', _t('注册原因最多 %d 个字', self::REASON_MAX), self::REASON_MAX);

        $errors = $validator->run($values);

        // 用户名 / 邮箱查重。已注册用户和排队中的申请都算占用，
        // 不然会出"审核通过那天名字被人抢了"这种烂事。
        if (!isset($errors['name']) && Model::nameTaken($values['name'])) {
            $errors['name'] = _t('这个用户名已经存在，或者已经有人用它提交过申请了');
        }
        if (!isset($errors['mail']) && Model::mailTaken($values['mail'])) {
            $errors['mail'] = _t('这个邮箱已经注册过，或者已经提交过申请了');
        }

        return $errors;
    }

    /**
     * 申请人的时区
     *
     * 没有 GeoIP，用浏览器报上来的最实在 —— 光靠 IP 不一定推得出时区。
     * 字符集收得很窄，因为这个值要落库、要显示给站长看。
     *
     * @return string
     */
    private function timezone(): string
    {
        $tz = preg_replace('/[^A-Za-z0-9_+\-\/]/', '', (string) $this->request->get('tz'));

        return substr((string) $tz, 0, 64);
    }

    // ---------------------------------------------------------------- 自助查询

    /**
     * 申请人查进度
     *
     * token 是 HMAC(id + created, secret)，和登录状态无关，
     * 所以换设备、退出登录都还能查；审核通过时换掉的 review token 也不影响它。
     */
    private function status(): void
    {
        $id = (int) $this->request->get('id');
        $token = (string) $this->request->get('t');

        $row = $id > 0 ? Model::find($id) : null;

        if ($row === null || !hash_equals(Plugin::queryToken($id, (int) $row['created']), $token)) {
            $this->page(
                _t('链接无效'),
                $this->alert(_t('这个查询链接不对。请用提交申请后给你的那个链接。'), 'error')
            );
            return;
        }

        $name = $this->escape((string) $row['name']);
        $mail = $this->escape((string) $row['mail']);
        $when = $this->escape(Plugin::stamp((int) $row['created']));

        $html = '<table class="mg-table">'
            . '<tr><th>' . $this->escape(_t('用户名')) . '</th><td>' . $name . '</td></tr>'
            . '<tr><th>' . $this->escape(_t('邮箱')) . '</th><td>' . $mail . '</td></tr>'
            . '<tr><th>' . $this->escape(_t('提交时间')) . '</th><td>' . $when . '</td></tr>'
            . '</table>';

        switch ($row['status']) {
            case Model::APPROVED:
                $title = _t('已通过审核');
                $html = $this->alert(_t('你的申请已经通过，可以登录了。'), 'success')
                    . '<p class="mg-intro">' . $this->escape(
                        _t('用注册时填的用户名和密码登录。')
                    ) . '</p>' . $html
                    . $this->noteBlock($row)
                    . '<p class="mg-foot"><a class="mg-btn" href="' . $this->escape($this->options->loginUrl) . '">'
                    . $this->escape(_t('去登录')) . '</a></p>';
                break;

            case Model::REJECTED:
                $title = _t('未通过审核');
                $html = $this->alert(_t('很抱歉，这次没有通过。'), 'notice')
                    . $this->noteBlock($row) . $html;
                break;

            default:
                $title = _t('审核中');
                $html = $this->alert(_t('申请已经收到，站长还没处理。'), 'notice')
                    . '<p class="mg-intro">' . $this->escape(
                        _t('这个页面随时可以回来看，链接不用换。通过之后这里会变成「可以登录了」。')
                    ) . '</p>' . $html;
                break;
        }

        $html .= '<p class="mg-foot"><a href="' . $this->escape($this->options->siteUrl) . '">'
            . $this->escape(_t('返回首页')) . '</a></p>';

        $this->page($title, $html);
    }

    /**
     * 站长的审核备注
     *
     * @param array $row
     * @return string
     */
    private function noteBlock(array $row): string
    {
        $note = trim((string) ($row['note'] ?? ''));
        if ($note === '') {
            return '';
        }

        return '<p class="mg-note-block"><strong>' . $this->escape(_t('站长的说明：'))
            . '</strong><br>' . nl2br($this->escape($note)) . '</p>';
    }

    // ---------------------------------------------------------------- 审核

    /**
     * 审核
     *
     * Telegram 消息里的按钮 / 后台面板的通过按钮，最后都会走到这里。
     */
    private function review(): void
    {
        $id = (int) $this->request->get('id');
        $act = (string) $this->request->get('act');
        $token = (string) $this->request->get('token');
        $note = Common::subStr(trim((string) $this->request->get('note')), 0, self::NOTE_MAX, '');

        $row = $id > 0 ? Model::find($id) : null;

        if ($row === null) {
            $this->page(_t('找不到这条申请'), $this->alert(_t('申请不存在，可能已经被删掉了。'), 'error'));
            return;
        }

        // requireAdmin() 已经挡掉没登录的人了，所以到这儿还要 token 的只有
        // 「在没登录的浏览器里点了 Telegram 的按钮」这一种情况
        $isAdmin = $this->user->hasLogin() && $this->user->pass('administrator', true);
        $tokenOk = !empty($row['token']) && hash_equals((string) $row['token'], $token);

        if (!$isAdmin && !$tokenOk) {
            $this->page(_t('链接已失效'), $this->alert(_t('这个审核链接不对，或者已经被用过了。'), 'error'));
            return;
        }

        if ($act !== 'approve' && $act !== 'reject') {
            $this->page(_t('参数不对'), $this->alert(_t('请从 Telegram 的按钮或后台「注册审核」里操作。'), 'error'));
            return;
        }

        if ($row['status'] !== Model::PENDING) {
            $this->page(
                _t('这条申请已经处理过了'),
                $this->alert(
                    _t('「%s」在 %s 已经%s。', $row['name'], Plugin::stamp((int) $row['reviewed']),
                        $row['status'] === Model::APPROVED ? _t('通过') : _t('拒绝')),
                    'notice'
                ) . $this->afterReview()
            );
            return;
        }

        [$title, $body] = $act === 'approve' ? $this->approve($row, $note) : $this->reject($row, $note);

        // 从后台面板点过来的，退回面板并弹个提示，比甩一张独立页面顺手
        if ($isAdmin && (string) $this->request->get('back') === 'panel') {
            Notice::alloc()->set(_t('%s：%s', $title, $row['name']), $act === 'approve' ? 'success' : 'notice');
            $this->response->redirect($this->panelUrl());
            return;
        }

        $this->page($title, $body);
    }

    /**
     * 通过
     *
     * 到这一步才真正建账号，用的就是申请人自己设的密码（申请时已经哈希过了）。
     *
     * @param array $row
     * @param string $note
     * @return array{0: string, 1: string} [标题, 正文]
     */
    private function approve(array $row, string $note): array
    {
        // 从提交到现在可能过了很久，名字和邮箱得再查一遍
        if (Model::nameTaken((string) $row['name'], (int) $row['id'])) {
            return [
                _t('没能通过'),
                $this->alert(_t('用户名「%s」已经被占用了，让申请人换个名字重新提交。', $row['name']), 'error')
                . $this->afterReview(),
            ];
        }
        if (Model::mailTaken((string) $row['mail'], (int) $row['id'])) {
            return [
                _t('没能通过'),
                $this->alert(_t('邮箱「%s」已经被占用了，让申请人换个邮箱重新提交。', $row['mail']), 'error')
                . $this->afterReview(),
            ];
        }

        $db = Db::get();

        $uid = (int) $db->query($db->insert('table.users')->rows([
            'name'       => $row['name'],
            'mail'       => $row['mail'],
            'screenName' => $row['name'],
            'password'   => $row['password'],
            'created'    => $this->options->time,
            'group'      => 'subscriber',
        ]));

        if ($uid <= 0) {
            return [_t('没能通过'), $this->alert(_t('建账号失败，请到后台手动处理。'), 'error') . $this->afterReview()];
        }

        Model::update((int) $row['id'], [
            'status'   => Model::APPROVED,
            'uid'      => $uid,
            'reviewed' => $this->options->time,
            'note'     => $note,
            // 换掉 token，Telegram / 邮件里那个审核链接就作废了。
            // 申请人手里的查询链接用的是另一个 token（HMAC），不受影响
            'token'    => Model::newToken(),
        ]);

        return [
            _t('已通过'),
            $this->alert(_t('「%s」已经通过审核，账号建好了。', $row['name']), 'success')
            . '<p class="mg-intro">' . $this->escape(
                _t('他下次去登录就能进。如果没有及时登录，让他用提交申请时拿到的那个查询链接看进度。')
            ) . '</p>'
            . $this->afterReview(),
        ];
    }

    /**
     * 拒绝
     *
     * @param array $row
     * @param string $note
     * @return array{0: string, 1: string}
     */
    private function reject(array $row, string $note): array
    {
        Model::update((int) $row['id'], [
            'status'   => Model::REJECTED,
            'reviewed' => $this->options->time,
            'note'     => $note,
            'token'    => Model::newToken(),
        ]);

        return [
            _t('已拒绝'),
            $this->alert(_t('「%s」的申请已经拒绝。', $row['name']), 'notice')
            . '<p class="mg-intro">' . $this->escape(
                _t('他在查询链接里能看到结果；上面填的备注也会一起显示给他。')
            ) . '</p>'
            . $this->afterReview(),
        ];
    }

    /**
     * 审完给个回后台的口子
     *
     * @return string
     */
    private function afterReview(): string
    {
        return '<p class="mg-foot"><a class="mg-btn" href="' . $this->escape($this->panelUrl()) . '">'
            . $this->escape(_t('回到「注册审核」')) . '</a></p>'
            . '<p class="mg-foot"><a href="' . $this->escape($this->options->siteUrl) . '">'
            . $this->escape(_t('返回首页')) . '</a></p>';
    }

    /**
     * 后台审核页地址
     *
     * @return string
     */
    private function panelUrl(): string
    {
        return Common::url(
            'extending.php?panel=' . urlencode(Plugin::NAME . '/' . Plugin::PANEL),
            $this->options->adminUrl
        );
    }

    // ---------------------------------------------------------------- Telegram 工具

    /**
     * 发一条测试消息，确认 token 和 chat id 都对
     */
    private function telegramTest(): void
    {
        $settings = Plugin::settings();

        if (!Notifier::hasTelegram($settings)) {
            $this->page(
                _t('还没配好'),
                $this->alert(_t('先在插件设置里填好 Telegram Bot Token 和 Chat ID。'), 'error')
                . $this->afterReview()
            );
            return;
        }

        $ok = Notifier::telegram(
            $settings,
            '✅ <b>MemberGate 通知测试</b>' . "\n\n"
            . _t('看到这条说明 bot token 和 chat id 都是通的，注册申请会推到这个会话。')
            . "\n\n" . _t('站点：%s', (string) $this->options->title)
        );

        $this->page(
            $ok ? _t('发送成功') : _t('发送失败'),
            $this->alert(
                $ok
                    ? _t('测试消息已经发出去，去 Telegram 看一眼。')
                    : _t('没发出去：%s', Notifier::lastError()),
                $ok ? 'success' : 'error'
            ) . $this->afterReview()
        );
    }

    /**
     * 拉 bot 最近收到的会话，帮站长找 chat id
     *
     * Telegram 的 bot 只能给「主动跟它说过话」的会话发消息，所以正确顺序是：
     * 自己先去跟 bot 说一句话 → 回到这里点一下 → 把列出来的 id 填进设置。
     */
    private function telegramDiscover(): void
    {
        $settings = Plugin::settings();

        // 先问 webhook 状态再看会话。顺序不能反 —— 每次请求都会重置 lastError，
        // 反过来调的话，getUpdates 那条真正的报错会被 getWebhookInfo 冲掉。
        // 而且 Telegram 里 getUpdates 和 webhook 天生互斥：挂了 webhook，getUpdates
        // 一定返回 "Conflict: can't use getUpdates method while webhook is active"。
        $webhook = Notifier::webhookInfo($settings);
        $webhookUrl = trim((string) ($webhook['url'] ?? ''));

        $chats = Notifier::discoverChats($settings);
        $error = Notifier::lastError();

        if (empty($chats)) {
            $this->page(
                _t('没拉到会话'),
                $webhookUrl !== ''
                    ? $this->webhookBlocked($webhook, $error)
                    : $this->alert(
                        $error !== ''
                            ? _t('没拉到：%s', $error)
                            : _t('bot 最近没收到任何消息。先在 Telegram 里找到你的 bot，给它发一句话，再回来点一次。'),
                        'error'
                    ) . $this->manualChatIdHint()
            );
            return;
        }

        $html = '<p class="mg-intro">' . $this->escape(
            _t('把你要接收通知的那个 id 填到插件设置里的「Telegram Chat ID」。')
        ) . '</p><table class="mg-table">';

        foreach ($chats as $chat) {
            $html .= '<tr><th>' . $this->escape($chat['title']) . '</th>'
                . '<td><code>' . $this->escape($chat['chat_id']) . '</code></td></tr>';
        }

        $html .= '</table><p class="mg-note">' . $this->escape(
            _t('群里 @ 过 bot 或者拉 bot 进群之后也能在这里看到群的 id（是负数，正常）。')
        ) . '</p>';

        $this->page(_t('拉到的会话'), $html . $this->afterReview());
    }

    /**
     * bot 挂着 webhook，getUpdates 用不了
     *
     * 这里只解释和指路，**不会**去动那个 webhook：删掉它会连待处理队列一起清空，
     * 排在后面的那个服务就永远收不到那些消息了。要不要停，是站长的事。
     *
     * @param array $webhook getWebhookInfo 的 result
     * @param string $error getUpdates 报的原文
     * @return string
     */
    private function webhookBlocked(array $webhook, string $error): string
    {
        $pending = (int) ($webhook['pending_update_count'] ?? 0);

        $html = $this->alert(_t('这个 bot 挂着 webhook，所以查不了会话。'), 'error')
            . '<p class="mg-intro">' . $this->escape(
                _t('Telegram 规定 getUpdates 和 webhook 只能二选一：挂上 webhook 之后，'
                    . '消息会往那个地址推，就不再进 getUpdates 的队列，'
                    . '所以「拉取最近会话」必然失败。')
            ) . '</p>'
            . '<p class="mg-link">' . $this->escape(_t('当前 webhook 指向：')) . '<br>'
            . '<code>' . $this->escape((string) $webhook['url']) . '</code>'
            . '<br>' . $this->escape(_t('待处理消息：%d 条', $pending)) . '</p>';

        // 这两句是给「要不要顺手把 webhook 停掉」这个决定用的
        $html .= '<p class="mg-note">' . $this->escape(
            $pending > 0
                ? _t('队列里压着 %d 条消息 —— 说明后面那个服务正在跑，'
                    . '这时候停掉 webhook 会把它们丢掉，别动。', $pending)
                : _t('队列是空的，看起来这个 webhook 没有在吃消息；'
                    . '如果你确认它是废弃的，可以自己去 API 停掉。')
        ) . '</p>'
            . '<p class="mg-note">' . $this->escape(
                _t('但你要的通知推送不受这个影响 —— 发消息是出方向，webhook 管的是收方向，'
                    . '挂着 webhook 也照样能往这个 bot 推。所以只要拿到 chat id 就行。')
            ) . '</p>'
            . '<p class="mg-note">' . $this->escape(_t('getUpdates 的原话：%s', $error)) . '</p>';

        return $html . $this->manualChatIdHint();
    }

    /**
     * 不走 getUpdates 也能拿到 chat id 的办法
     *
     * 这是最省事也最安全的一条路：第三方信息 bot 会把你的 id 直接回给你。
     *
     * @return string
     */
    private function manualChatIdHint(): string
    {
        return '<p class="mg-intro"><strong>' . $this->escape(
            _t('推荐：用 @userinfobot 拿 id，不用动 webhook')
        ) . '</strong></p>'
            . '<ol class="mg-steps">'
            . '<li>' . $this->escape(
                _t('在 Telegram 里搜 @userinfobot，给它随便发一句话。')
            ) . '</li>'
            . '<li>' . $this->escape(
                _t('它会回一段信息，里面的 Id 那串数字就是你的 Telegram 用户 id。'
                    . '私聊 bot 的 chat id 就等于这个 id。')
            ) . '</li>'
            . '<li>' . $this->escape(
                _t('把这串数字填到插件设置的「Telegram Chat ID」，保存。')
            ) . '</li>'
            . '</ol>'
            . '<p class="mg-note">' . $this->escape(
                _t('想推到群里的话，把 @userinfobot 拉进群（或者在群里 @ 它），'
                    . '它会给一个负数 id，那个就是群的 chat id，用完把这个 bot 踢出去就行。')
            ) . '</p>'
            . '<p class="mg-note">' . $this->escape(
                _t('隐私提醒：私聊的 chat id 就是你的个人 Telegram 账号 id，永久且改不掉，'
                    . '填了它等于把这串标识存进站点数据库。想避开的话就推到一个只有你和 bot 的群，'
                    . '群 id 是负数，跟你个人账号没有对应关系。')
            ) . '</p>';
    }

    /**
     * 审核链接
     *
     * @param int $id
     * @param string $token
     * @param string $act approve|reject
     * @return string
     */
    private function reviewUrl(int $id, string $token, string $act): string
    {
        return Common::url(
            '/action/' . Plugin::ACTION . '?do=review&id=' . $id
            . '&act=' . $act . '&token=' . urlencode($token),
            $this->options->index
        );
    }

    // ---------------------------------------------------------------- 输出

    /**
     * CSRF token
     *
     * @return string
     */
    private function csrfToken(): string
    {
        return md5($this->options->secret . '&' . self::CSRF_SALT);
    }

    /**
     * @param string $text
     * @return string
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 提示条
     *
     * @param string $message
     * @param string $type success|error|notice
     * @return string
     */
    private function alert(string $message, string $type): string
    {
        $colors = [
            'success' => ['#E7F6EC', '#2B8A4B'],
            'error'   => ['#FDECEC', '#B02A2A'],
            'notice'  => ['#F1F1F5', '#3A3A46'],
        ];
        [$bg, $fg] = $colors[$type] ?? $colors['notice'];

        return '<p class="mg-alert" style="background:' . $bg . ';color:' . $fg . '">'
            . $this->escape($message) . '</p>';
    }

    /**
     * 套一整页输出然后结束请求
     *
     * 样式全内联 / 内嵌，不依赖主题，也不依赖后台的 CSS。
     * 配色抄的是站点主题的调子，换主题顶多是不太像，不会难看到不能用。
     *
     * @param string $title
     * @param string $inner
     */
    private function page(string $title, string $inner): void
    {
        $siteTitle = (string) $this->options->title;
        $charset = (string) $this->options->charset;
        $home = $this->escape($this->options->siteUrl);

        $head = '<!DOCTYPE html><html lang="zh-CN"><head>'
            . '<meta charset="' . $this->escape($charset) . '">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $this->escape($title) . ' &raquo; ' . $this->escape($siteTitle) . '</title>'
            . '<style>'
            . '*{box-sizing:border-box}'
            . 'body{margin:0;padding:40px 20px;background:#FBFBFC;color:#1A1A1F;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",'
            . '"Hiragino Sans GB","Microsoft YaHei",sans-serif}'
            . '.mg-wrap{max-width:460px;margin:0 auto}'
            . '.mg-brand{display:block;margin:0 0 22px;font-size:20px;font-weight:700;'
            . 'color:#1A1A1F;text-decoration:none;text-align:center}'
            . '.mg-card{background:#fff;border:1px solid #ECECF0;border-radius:16px;padding:28px 26px;'
            . 'box-shadow:0 1px 3px rgba(20,20,30,.04)}'
            . '.mg-title{margin:0 0 18px;font-size:19px;font-weight:700;text-align:center}'
            . '.mg-intro{margin:0 0 18px;color:#5A5A66;font-size:14px}'
            . '.mg-alert{margin:0 0 16px;padding:11px 14px;border-radius:10px;font-size:14px}'
            . '.mg-form,.mg-field{display:block}'
            . '.mg-field{margin:0 0 16px}'
            . '.mg-label{display:block;margin:0 0 6px;font-size:13px;font-weight:600;color:#5A5A66}'
            . '.mg-field input,.mg-field textarea{display:block;width:100%;padding:10px 13px;'
            . 'border:1px solid #DFDFE6;border-radius:10px;background:#fff;color:#1A1A1F;'
            . 'font:inherit;font-size:15px}'
            . '.mg-field textarea{resize:vertical;min-height:92px}'
            . '.mg-field input:focus,.mg-field textarea:focus{outline:none;border-color:#F2789A;'
            . 'box-shadow:0 0 0 3px rgba(242,120,154,.16)}'
            . '.mg-error{display:block;margin:6px 0 0;font-size:12.5px;font-style:normal;color:#BE4568}'
            . '.mg-submit{display:block;width:100%;margin-top:22px;padding:11px 20px;border:0;'
            . 'border-radius:999px;background:#F2789A;color:#fff;font:inherit;font-size:15px;'
            . 'font-weight:600;cursor:pointer}'
            . '.mg-submit:hover{background:#A93A59}'
            . '.mg-copy{margin-top:14px;background:#1A1A1F}'
            . '.mg-copy:hover{background:#3A3A46}'
            . '.mg-btn{display:inline-block;padding:9px 22px;border-radius:999px;'
            . 'background:#F2789A;color:#fff;text-decoration:none;font-weight:600}'
            . '.mg-btn:hover{background:#A93A59}'
            . '.mg-link{margin:0;padding:12px 14px;border:1px dashed #DFDFE6;border-radius:10px;'
            . 'background:#F2F2F5;word-break:break-all;font-size:13px}'
            . '.mg-link a{color:#BE4568}'
            . '.mg-note{margin:18px 0 0;color:#6E6E7A;font-size:13px}'
            . '.mg-note-block{margin:0 0 16px;padding:12px 14px;border-radius:10px;background:#F2F2F5;'
            . 'font-size:14px}'
            . '.mg-table{width:100%;border-collapse:collapse;margin:0 0 16px;font-size:14px}'
            . '.mg-table th{text-align:left;padding:7px 12px 7px 0;color:#6E6E7A;font-weight:600;'
            . 'white-space:nowrap;vertical-align:top}'
            . '.mg-table td{padding:7px 0;word-break:break-all}'
            . '.mg-steps{margin:0 0 16px;padding-left:22px;color:#5A5A66;font-size:14px}'
            . '.mg-steps li{margin:0 0 6px}'
            . '.mg-foot{margin:18px 0 0;text-align:center;color:#6E6E7A;font-size:13.5px}'
            . '.mg-foot a{color:#BE4568;text-decoration:none}'
            . '.mg-foot a:hover{text-decoration:underline}'
            . '.mg-foot a.mg-btn{color:#fff}'
            . '.mg-foot span{margin:0 8px;color:#DFDFE6}'
            . '</style></head><body>';

        echo $head . '<div class="mg-wrap">'
            . '<a class="mg-brand" href="' . $home . '">' . $this->escape($siteTitle) . '</a>'
            . '<div class="mg-card">'
            . ($title !== '' ? '<h1 class="mg-title">' . $this->escape($title) . '</h1>' : '')
            . $inner
            . '</div></div></body></html>';

        exit;
    }
}
