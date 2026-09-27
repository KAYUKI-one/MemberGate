<?php

namespace TypechoPlugin\MemberGate;

use Throwable;
use Typecho\Common;
use Typecho\Date;
use Typecho\Db;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Response;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Notice;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 注册审核 + 文章权限
 *
 * 两件事：
 *
 * 1. 把 Typecho 自带的注册流程换掉。填用户名、邮箱、密码、注册原因，记录 IP / 时区 / UA，
 *    申请推送到站长的 Telegram，站长点消息里的按钮（或者进后台「注册审核」）通过之后账号才建出来。
 *    通过之前申请人**没有账号**，登录不上，自然也拿不到任何权限。
 *    申请人那边不发邮件，靠提交时给的「查询进度」链接和登录提示自己看结果。
 *
 * 2. 给整篇文章上锁。写文章时把「仅注册用户可见」选成「是」，未登录访客在首页、分类、标签、
 *    搜索、订阅里都看不到它，直接访问文章链接返回 404。
 *
 * @package 注册与权限管理
 * @author Claude Code
 * @version 1.0.0
 * @link https://typecho.org
 */
class Plugin implements PluginInterface
{
    /** 插件目录名，读设置、拼路径都要用 */
    public const NAME = 'MemberGate';

    /** action 名。路由是 /action/[action:alpha]，只认纯字母，不能带连字符 */
    public const ACTION = 'memberregister';

    /** 整篇锁用的自定义字段名 */
    public const FIELD = 'member';

    /** 后台面板文件（相对插件目录） */
    public const PANEL = 'Admin/Panel.php';

    /** 后台菜单名，注册和注销要用同一个字符串 */
    private const MENU = '注册审核';

    /** 每次请求只查一次锁定文章集合 */
    private static ?array $locked = null;

    /** 设置缓存 */
    private static ?array $settings = null;

    /**
     * 激活
     *
     * 钩子必须在这里挂 —— Typecho 会把钩子写进 options 表存下来，
     * 之后每次请求直接从表里读，activate() 不会再有第二次执行机会。
     *
     * @throws PluginException
     */
    public static function activate()
    {
        try {
            Model::install();
        } catch (Throwable $e) {
            // activate 只截获 Plugin\Exception，别的异常会变成整站 500，
            // 包一层，让后台能弹出一句人话
            throw new PluginException(_t('建表失败：%s', $e->getMessage()), 500);
        }

        Helper::addAction(self::ACTION, 'TypechoPlugin\MemberGate\Widget\Register');

        $index = Helper::addMenu(self::MENU);
        Helper::addPanel($index, self::NAME . '/' . self::PANEL, self::MENU, _t('待处理的注册申请'), 'administrator');

        self::registerHooks();
    }

    /**
     * 禁用
     *
     * Plugin::factory() 挂上去的钩子由框架自己摘（var/Typecho/Plugin.php:113），
     * 但写进 options 表的 action / 菜单得自己清。表不删 —— 审核记录是账，留着。
     */
    public static function deactivate()
    {
        try {
            Helper::removeAction(self::ACTION);
            $index = Helper::removeMenu(self::MENU);
            Helper::removePanel($index, self::NAME . '/' . self::PANEL);
        } catch (Throwable $e) {
            // 清不干净也不该拦住禁用
        }
    }

    /**
     * 挂钩子
     */
    private static function registerHooks(): void
    {
        // 列表、搜索、分类、订阅、单篇都从这里过
        \Typecho\Plugin::factory('Widget\Archive')->handleInit = __CLASS__ . '::filterArchive';

        // 每一行内容 push 时都会过这里，用来兜住相邻文章导航泄露的标题
        \Typecho\Plugin::factory('Widget\Base\Contents')->filter = __CLASS__ . '::filterRow';

        // 写文章 / 写页面的「自定义字段」区域
        \Typecho\Plugin::factory('Widget\Base\Contents')->getDefaultFieldItems = __CLASS__ . '::addLockField';

        // 后台每个页面都会触发，用来把自带的注册页换掉
        \Typecho\Plugin::factory('admin/common.php')->begin = __CLASS__ . '::redirectRegisterPage';

        // 还没过审的人来登录，告诉他真实原因
        \Typecho\Plugin::factory('Widget\Login')->loginFailure = __CLASS__ . '::explainPendingLogin';
    }

    /**
     * 插件设置
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $form->addInput(new Text(
            'tgToken',
            null,
            '',
            _t('Telegram Bot Token'),
            _t('跟 @BotFather 建一个 bot，它给你的那串 token。')
        ));

        $form->addInput(new Text(
            'tgChat',
            null,
            '',
            _t('Telegram Chat ID'),
            _t('要推到哪个会话。最省事的拿法是搜 @userinfobot，给它发一句话，'
                . '它回给你的那串 Id 数字填这里（群就把它拉进群，会给一个负数）。'
                . '也可以到后台「注册审核」点「拉取最近会话」——'
                . '但那个 bot 如果挂着 webhook，这条会失败（点开会说明怎么办）。')
        ));

        $form->addInput(new Text(
            'formTitle',
            null,
            _t('申请注册'),
            _t('注册页标题'),
            ''
        ));

        $form->addInput(new Textarea(
            'formIntro',
            null,
            '',
            _t('注册页说明'),
            _t('注册页标题下面的一段话，讲清楚为什么要注册、会怎么审核。')
        ));

        $form->addInput(new Text(
            'maxPendingPerIp',
            null,
            '3',
            _t('同 IP 待审上限'),
            _t('同一个 IP 最多同时压着几条待审申请，超了直接拒绝。填 0 表示不限制。')
        ));

        // ---- 下面是备选的邮件通道。整块默认关着，不用管。
        $form->addInput(new Text(
            'toMail',
            null,
            '',
            _t('【可选】收件邮箱'),
            _t('留空就完全不发邮件。填了的话，申请也会同时发到这个邮箱（多个用逗号隔开）。')
        ));

        $form->addInput(new Select(
            'mailer',
            [
                ''     => _t('PHP mail()'),
                'smtp' => _t('SMTP'),
            ],
            '',
            _t('【可选】发信方式'),
            _t('主机商默认的 mail() 大多发不出去，要用就选 SMTP。')
        ));

        $form->addInput(new Text('smtpHost', null, '', _t('【可选】SMTP 主机'), _t('例如 smtp.qq.com。')));

        $form->addInput(new Text('smtpPort', null, '465', _t('【可选】SMTP 端口'), _t('ssl 常用 465，STARTTLS 常用 587。')));

        $form->addInput(new Text('smtpUser', null, '', _t('【可选】SMTP 账号'), _t('一般就是邮箱地址。留空表示不认证。')));

        $form->addInput(new Text('smtpPass', null, '', _t('【可选】SMTP 密码'), _t('QQ 邮箱之类要用「授权码」。')));

        $form->addInput(new Select(
            'smtpSecure',
            [
                'ssl' => _t('SSL（直连加密，465）'),
                'tls' => _t('STARTTLS（587）'),
                ''    => _t('不加密'),
            ],
            'ssl',
            _t('【可选】SMTP 加密方式'),
            ''
        ));

        $form->addInput(new Text('fromMail', null, '', _t('【可选】发件人邮箱'), _t('留空则用收件邮箱。')));

        $form->addInput(new Text('fromName', null, '', _t('【可选】发件人名称'), _t('留空则用站点名称。')));
    }

    /**
     * 个人设置
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    // ---------------------------------------------------------------- 权限判断

    /**
     * 当前访问者是不是「已通过审核的注册用户」
     *
     * subscriber 及以上（含贡献者、编辑、管理员）都算。用 pass() 而不是自己查用户组，
     * 这样以后加新组、改组的权重都不用动这里。
     *
     * @return bool
     */
    public static function isMember(): bool
    {
        try {
            $user = User::alloc();
            return $user->hasLogin() && $user->pass('subscriber', true);
        } catch (Throwable $e) {
            // 判断不出来就当没权限 —— 权限功能宁可误锁也不能误放
            return false;
        }
    }

    /**
     * 所有锁定的文章 cid
     *
     * 每次请求查一次就够：列表页要拿它拼查询条件，行过滤器要拿它认标题。
     *
     * @return int[]
     */
    public static function lockedCids(): array
    {
        if (self::$locked !== null) {
            return self::$locked;
        }

        self::$locked = [];
        try {
            $db = Db::get();
            $rows = $db->fetchAll(
                $db->select('cid')->from('table.fields')
                    ->where('name = ?', self::FIELD)
                    ->where('str_value = ?', '1')
            );
            self::$locked = array_map('intval', array_column($rows, 'cid'));
        } catch (Throwable $e) {
            // 查不出来就当没有锁定文章，至少不能把整站搞挂
        }

        return self::$locked;
    }

    // ---------------------------------------------------------------- 文章锁

    /**
     * 归档查询的收口
     *
     * 挂在 handleInit 上（var/Widget/Archive.php:651），时机是 select 拼完之后、各种
     * handle 之前，所以首页 / 分类 / 标签 / 搜索 / 日期 / 作者 / 单篇 / 订阅全都覆盖到了。
     *
     * 单篇查不到行时核心会自己抛 404（var/Widget/Archive.php:1664），不用另外写。
     *
     * @param mixed $archive
     * @param mixed $select
     */
    public static function filterArchive($archive = null, $select = null)
    {
        if (!($select instanceof Db\Query) || self::isMember()) {
            return;
        }

        $locked = self::lockedCids();
        if (empty($locked)) {
            return;
        }

        // 用参数绑定把 cid 列表展开成 (1,2,3)。
        // 不能写子查询 —— Query::filterColumn 会把 SELECT / FROM / WHERE 当成列名加反引号。
        $select->where('table.contents.cid NOT IN ?', $locked);
    }

    /**
     * 行过滤器
     *
     * 列表查询已经把锁定文章排除掉了，但 thePrev() / theNext()（var/Widget/Archive.php:884）
     * 是另外拼的查询，不走 handleInit，访客会在文章底部看到锁定文章的标题。
     * 这里把标题换掉。链接本身会 404，地址不算漏。
     *
     * @param mixed $row
     * @param mixed $archive
     * @param mixed $original
     * @return mixed
     */
    public static function filterRow($row = null, $archive = null, $original = null)
    {
        if (!is_array($row) || empty($row['cid']) || self::isMember()) {
            return $row;
        }

        $locked = self::lockedCids();
        if (!empty($locked) && in_array((int) $row['cid'], $locked, true)) {
            $row['title'] = _t('仅注册用户可见');
        }

        return $row;
    }

    /**
     * 写文章页加一个「仅注册用户可见」
     *
     * EditTrait 会自动把 name 改写成 fields[member]、回填已有值，
     * getFields() / applyFields() 负责落库，不用自己写保存逻辑。
     *
     * @param mixed $layout
     */
    public static function addLockField($layout = null)
    {
        if (!($layout instanceof Layout)) {
            return;
        }

        $layout->addItem(new Select(
            self::FIELD,
            [
                ''  => _t('否'),
                '1' => _t('是，仅注册用户可见'),
            ],
            '',
            _t('仅注册用户可见'),
            _t('选「是」后，未登录访客在首页、分类、标签、搜索和订阅里都看不到这篇，'
                . '直接访问它的链接返回 404。管理员不受影响。')
        ));
    }

    // ---------------------------------------------------------------- 注册入口

    /**
     * 把 Typecho 自带的注册页顶掉
     *
     * 主题里所有「注册」链接都指向 $options->registerUrl，也就是 /admin/register.php。
     * 那个 URL 由 Widget\Options::___registerUrl() 生成，插件改不了（Widget::__get 优先走
     * 同名方法，钩子够不着），所以在 HTTP 层拦：admin/common.php 每个后台页面都会触发 begin，
     * 而 register.php 第一件事就是 include common.php。
     *
     * @return void
     */
    public static function redirectRegisterPage()
    {
        // 后台每个页面都会走这里，先做最便宜的一次判断。
        // 用 SCRIPT_NAME 而不是 PHP_SELF：后者带 PATH_INFO，basename 会取到路径尾巴
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'register.php') {
            return;
        }

        try {
            if (!Options::alloc()->allowRegister) {
                return;
            }

            self::redirect(self::registerUrl());
        } catch (Throwable $e) {
            // 拦不住就让自带的注册页照常显示，不能白屏
        }
    }

    /**
     * 302 跳走并结束请求
     *
     * 这里不能用 $this->response->redirect() —— 那要有个 widget 才能拿到。
     * Typecho\Response 本身也没有 redirect()，只有 setHeader + respond()。
     * respond() 末尾是 exit，所以在钩子里调用能直接把请求收掉。
     *
     * @param string $url
     * @return void
     */
    private static function redirect(string $url): void
    {
        Response::getInstance()
            ->setStatus(302)
            ->setHeader('Location', (string) Common::safeUrl($url))
            ->respond();
    }

    /**
     * 注册页地址
     *
     * @return string
     */
    public static function registerUrl(): string
    {
        return Common::url('/action/' . self::ACTION, Options::alloc()->index);
    }

    /**
     * 按站点时区格式化时间戳
     *
     * 不能直接用 date() —— 那走的是服务器时区，跟站上设置的那个对不上，
     * 显示给站长的时间会莫名其妙差几个小时。Typecho\Date 才是站点的时区。
     *
     * @param int $timestamp
     * @param string $format
     * @return string
     */
    public static function stamp(int $timestamp, string $format = 'Y-m-d H:i'): string
    {
        try {
            return (new Date($timestamp))->format($format);
        } catch (Throwable $e) {
            return date($format, $timestamp);
        }
    }

    /**
     * 状态查询 token
     *
     * 用 secret 做 HMAC，不再多存一列 —— 审核通过时我们会换掉 review token 让邮件链接作废，
     * 但申请人手里那个查询链接必须一直有效，所以两者不能用同一个凭据。
     *
     * 和用户的登录状态无关（不像 Widget\Security 的 token 会掺进 authCode），
     * 所以申请人退出登录、换设备、换浏览器都还能查。
     *
     * @param int $id
     * @param int $created
     * @return string
     */
    public static function queryToken(int $id, int $created): string
    {
        return substr(
            hash_hmac('sha256', 'membergate:status:' . $id . ':' . $created, Options::alloc()->secret),
            0,
            32
        );
    }

    /**
     * 申请人自助查询审核状态的地址
     *
     * @param array $row
     * @return string
     */
    public static function statusUrl(array $row): string
    {
        return Common::url(
            '/action/' . self::ACTION . '?do=status&id=' . (int) $row['id']
            . '&t=' . self::queryToken((int) $row['id'], (int) $row['created']),
            Options::alloc()->index
        );
    }

    /**
     * 还没过审的人来登录时，把真实原因告诉他
     *
     * 申请人过审前根本没有账号，核心只会说「用户名或密码无效」，很劝退。
     * 核心在调这个钩子之前已经 sleep(3) 防穷举了，所以这里直接跳走不会削弱防护。
     *
     * @param mixed $user
     * @param mixed $name
     * @param mixed $password
     * @param mixed $remember
     * @return void
     */
    public static function explainPendingLogin($user = null, $name = null, $password = null, $remember = null)
    {
        try {
            if (!is_string($name) || $name === '') {
                return;
            }

            $status = Model::statusOf($name);
            if ($status === Model::PENDING) {
                $message = _t('你的注册申请还在审核中，通过之后才能登录。');
            } elseif ($status === Model::REJECTED) {
                $message = _t('你的注册申请没有通过审核。');
            } else {
                return;
            }

            // Notice 是写 cookie、由后台的 common-js.php 弹出来的
            Notice::alloc()->set($message, 'notice');
            self::redirect(Options::alloc()->loginUrl);
        } catch (Throwable $e) {
            // 退回核心默认的「用户名或密码无效」
        }
    }

    // ---------------------------------------------------------------- 设置

    /**
     * 读设置
     *
     * Options::plugin() 在「插件启用了、但还没进过设置页」时会直接抛异常，
     * 抛出去就是整站 500 —— 必须兜住。
     *
     * @return array
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        // 空字符串对大多数项都是有意义的取值（比如 smtpSecure 的「不加密」），
        // 所以不能拿"空就退回默认"来兜底 —— 那会把用户明确关掉的开关又打开。
        // 只有下面这两项，空才等于「没填过」。
        $settings = [
            'tgToken'         => '',
            'tgChat'          => '',
            'formTitle'       => _t('申请注册'),
            'formIntro'       => '',
            'maxPendingPerIp' => '3',
            // 下面是备选的邮件通道，默认全空 = 不发邮件
            'toMail'          => '',
            'mailer'          => '',
            'smtpHost'        => '',
            'smtpPort'        => '465',
            'smtpUser'        => '',
            'smtpPass'        => '',
            'smtpSecure'      => 'ssl',
            'fromMail'        => '',
            'fromName'        => '',
        ];

        try {
            $config = Options::alloc()->plugin(self::NAME);

            foreach (array_keys($settings) as $key) {
                $settings[$key] = trim((string) $config->{$key});
            }

            if ($settings['formTitle'] === '') {
                $settings['formTitle'] = _t('申请注册');
            }
            if ($settings['maxPendingPerIp'] === '') {
                $settings['maxPendingPerIp'] = '3';
            }
        } catch (Throwable $e) {
            // 「插件已启用但没进过设置页」时 plugin() 会抛，用上面那套默认值
        }

        if ($settings['fromMail'] === '') {
            $settings['fromMail'] = $settings['toMail'];
        }
        if ($settings['fromName'] === '') {
            $settings['fromName'] = (string) Options::alloc()->title;
        }

        return self::$settings = $settings;
    }
}
