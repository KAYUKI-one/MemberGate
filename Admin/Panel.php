<?php
/**
 * 注册审核
 *
 * 这个文件由 admin/extending.php 引入，所以作用域里已经有 admin/common.php 建好的
 * $options / $user / $request / $response / $security / $menu。
 *
 * 类名一律写全，不写 use —— 被 require 进来的文件里放 use 容易让人看懵。
 */

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

/** @var \Widget\Options $options */
/** @var \Widget\User $user */
/** @var \Widget\Request $request */

// 非管理员直接挡掉。admin/menu.php 里也会拦一次，这里先拦更直接
$user->pass('administrator');

$pluginName = \TypechoPlugin\MemberGate\Plugin::NAME;
$panelFile = $pluginName . '/' . \TypechoPlugin\MemberGate\Plugin::PANEL;

$settings = \TypechoPlugin\MemberGate\Plugin::settings();
$actionUrl = \Typecho\Common::url('/action/' . \TypechoPlugin\MemberGate\Plugin::ACTION, $options->index);
$selfUrl = \Typecho\Common::url('extending.php?panel=' . urlencode($panelFile), $options->adminUrl);
$configUrl = $options->adminUrl('options-plugin.php?config=' . $pluginName, true);

$tab = (string) $request->get('tab');
if (!in_array($tab, ['pending', 'approved', 'rejected'], true)) {
    $tab = 'pending';
}

$counts = [
    'pending'  => \TypechoPlugin\MemberGate\Model::count(\TypechoPlugin\MemberGate\Model::PENDING),
    'approved' => \TypechoPlugin\MemberGate\Model::count(\TypechoPlugin\MemberGate\Model::APPROVED),
    'rejected' => \TypechoPlugin\MemberGate\Model::count(\TypechoPlugin\MemberGate\Model::REJECTED),
];

$rows = \TypechoPlugin\MemberGate\Model::rows($tab, 200);

/** 输出转义 */
$e = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$hasTelegram = \TypechoPlugin\MemberGate\Notifier::hasTelegram($settings);
$hasMail = \TypechoPlugin\MemberGate\Notifier::hasMail($settings);
$isPending = $tab === 'pending';

$tabs = [
    'pending'  => _t('待审核'),
    'approved' => _t('已通过'),
    'rejected' => _t('已拒绝'),
];

include __TYPECHO_ROOT_DIR__ . '/admin/header.php';
include __TYPECHO_ROOT_DIR__ . '/admin/menu.php';
?>
<main class="main">
    <div class="body container">
        <?php include __TYPECHO_ROOT_DIR__ . '/admin/page-title.php'; ?>

        <?php if (!$hasTelegram && !$hasMail): ?>
            <div class="message error">
                <strong><?php _e('还没有可用的通知渠道。'); ?></strong>
                <?php _e('申请照样会存下来、这个页面也能审核，但有人提交时你收不到任何提醒，'
                    . '得自己隔三差五来看一眼。'); ?>
                <a href="<?php echo $e($configUrl); ?>"><?php _e('去设置'); ?></a>
            </div>
        <?php else: ?>
            <p class="description">
                <?php _e('当前通知渠道：'); ?>
                <?php echo $e(implode(' + ', array_filter([
                    $hasTelegram ? 'Telegram' : '',
                    $hasMail ? _t('邮件') : '',
                ]))); ?>
            </p>
        <?php endif; ?>

        <p>
            <a class="btn btn-s" href="<?php echo $e($actionUrl . '?do=tgtest'); ?>">
                <?php _e('发一条测试消息'); ?>
            </a>
            <a class="btn btn-s" href="<?php echo $e($actionUrl . '?do=tgdiscover'); ?>">
                <?php _e('拉取 bot 最近会话（找 Chat ID）'); ?>
            </a>
            <a class="btn btn-s" href="<?php echo $e($configUrl); ?>"><?php _e('插件设置'); ?></a>
        </p>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <div class="typecho-list-operate">
                    <ul class="typecho-option-tabs">
                        <?php foreach ($tabs as $key => $label): ?>
                            <li<?php echo $key === $tab ? ' class="current"' : ''; ?>>
                                <a href="<?php echo $e($selfUrl . '&tab=' . $key); ?>"><?php echo $e($label); ?>
                                    <?php if ($counts[$key] > 0): ?>
                                        <span class="balloon"><?php echo (int) $counts[$key]; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <?php if (empty($rows)): ?>
                    <p class="description"><?php _e('这里还没有东西。'); ?></p>
                <?php else: ?>
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="22%"/>
                            <col width=""/>
                            <col width="28%"/>
                        </colgroup>
                        <thead>
                        <tr>
                            <th><?php _e('申请人'); ?></th>
                            <th><?php _e('提交信息'); ?></th>
                            <th><?php echo $isPending ? _t('操作') : _t('处理结果'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $e($row['name']); ?></strong><br>
                                    <a href="mailto:<?php echo $e($row['mail']); ?>">
                                        <?php echo $e($row['mail']); ?>
                                    </a><br>
                                    <span class="description">
                                        <?php echo $e(\TypechoPlugin\MemberGate\Plugin::stamp((int) $row['created'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="description">
                                        IP <?php echo $e($row['ip'] !== '' ? $row['ip'] : '—'); ?>
                                        ／ <?php _e('时区'); ?>
                                        <span><?php echo $e($row['timezone'] !== '' ? $row['timezone'] : _t('浏览器没报')); ?></span>
                                    </span><br>
                                    <span class="description"><?php echo $e($row['agent']); ?></span><br>
                                    <?php if (trim((string) $row['referer']) !== ''): ?>
                                        <span class="description"><?php echo $e($row['referer']); ?></span><br>
                                    <?php endif; ?>
                                    <blockquote><?php echo nl2br($e($row['reason'])); ?></blockquote>
                                </td>
                                <td>
                                    <?php if ($isPending): ?>
                                        <form method="post" action="<?php echo $e($actionUrl); ?>">
                                            <input type="hidden" name="do" value="review">
                                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                            <input type="hidden" name="back" value="panel">
                                            <?php /*
                                             * 表单里带上这一行的 token。审核动作只认
                                             * 「登录着的管理员」或「拿着这个只出现在管理员页面上的随机串」，
                                             * 所以在别人站上伪造一个 POST 过来是审不动的。
                                             */ ?>
                                            <input type="hidden" name="token"
                                                   value="<?php echo $e($row['token']); ?>">
                                            <input type="text" class="text-s w-100" name="note"
                                                   placeholder="<?php _e('备注（可选，拒绝时会显示给申请人）'); ?>">
                                            <p>
                                                <button type="submit" class="btn btn-s primary" name="act"
                                                        value="approve"><?php _e('通过'); ?></button>
                                                <button type="submit" class="btn btn-s" name="act"
                                                        value="reject"><?php _e('拒绝'); ?></button>
                                            </p>
                                        </form>
                                    <?php else: ?>
                                        <span class="description">
                                            <?php echo $e(\TypechoPlugin\MemberGate\Plugin::stamp((int) $row['reviewed'])); ?>
                                            <?php if ((int) $row['uid'] > 0): ?>
                                                ／ <a href="<?php
                                                echo $e($options->adminUrl('user.php?uid=' . (int) $row['uid'], true));
                                                ?>"><?php _e('查看账号'); ?></a>
                                            <?php endif; ?>
                                        </span>
                                        <?php if (trim((string) $row['note']) !== ''): ?>
                                            <blockquote><?php echo nl2br($e($row['note'])); ?></blockquote>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <p class="description">
                    <?php _e('通过审核时会现场建账号（用户组 subscriber），密码就是申请人自己设的那个。'
                        . '通过之前他没有账号，登录不上，也看不到任何「仅注册用户可见」的内容。'); ?>
                </p>
            </div>
        </div>
    </div>
</main>
<?php
include __TYPECHO_ROOT_DIR__ . '/admin/common-js.php';
include __TYPECHO_ROOT_DIR__ . '/admin/footer.php';
