<?php

namespace TypechoPlugin\MemberGate;

use Typecho\Common;
use Typecho\Db;
use Typecho\Db\Exception as DbException;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 注册申请表
 *
 * 申请在通过审核之前不写 table.users —— 也就是申请人**根本没有账号**，
 * 登录不上、拿不到任何权限。通过审核的那一刻才用申请人自己设的密码建号。
 *
 * @package MemberGate
 */
class Model
{
    /** 表名，table. 前缀由 Typecho 的 Query 负责替换 */
    public const TABLE = 'table.member_requests';

    /** 待审核 */
    public const PENDING = 'pending';

    /** 已通过 */
    public const APPROVED = 'approved';

    /** 已拒绝 */
    public const REJECTED = 'rejected';

    /**
     * @return Db
     */
    private static function db(): Db
    {
        return Db::get();
    }

    /**
     * 建表
     *
     * 三种驱动分开写：Typecho 的 Query 只管增删改查，建表得自己拼 SQL。
     *
     * @throws DbException
     */
    public static function install(): void
    {
        $db = self::db();
        $table = $db->getPrefix() . 'member_requests';
        $driver = $db->getAdapter()->getDriver();

        $columns = [
            'name'       => 'varchar(32) NOT NULL',
            'mail'       => 'varchar(64) NOT NULL',
            'password'   => 'varchar(128) NOT NULL',
            'reason'     => 'text',
            'ip'         => 'varchar(64)',
            'timezone'   => 'varchar(64)',
            'agent'      => 'varchar(255)',
            'referer'    => 'varchar(255)',
            'status'     => "varchar(16) NOT NULL DEFAULT 'pending'",
            'token'      => 'varchar(64)',
            'uid'        => 'int',
            'mail_sent'  => 'int NOT NULL DEFAULT 0',
            'note'       => 'varchar(255)',
            'created'    => 'int',
            'reviewed'   => 'int',
        ];

        if ($driver === 'pgsql') {
            $body = ['"id" SERIAL PRIMARY KEY'];
            foreach ($columns as $name => $type) {
                $body[] = '"' . $name . '" ' . $type;
            }
            $db->query('CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', $body) . ')');
            foreach (['status', 'ip', 'mail'] as $index) {
                $db->query('CREATE INDEX IF NOT EXISTS "' . $table . '_' . $index . '_idx"'
                    . ' ON "' . $table . '" ("' . $index . '")');
            }
            return;
        }

        if ($driver === 'sqlite') {
            $body = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
            foreach ($columns as $name => $type) {
                $body[] = '"' . $name . '" ' . $type;
            }
            $db->query('CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', $body) . ')');
            foreach (['status', 'ip', 'mail'] as $index) {
                $db->query('CREATE INDEX IF NOT EXISTS "' . $table . '_' . $index . '_idx"'
                    . ' ON "' . $table . '" ("' . $index . '")');
            }
            return;
        }

        // MySQL / MariaDB
        // 类型原样写、不 strtoupper —— 那会把 DEFAULT 'pending' 里的字面量也一起大写掉，
        // 建出来的表默认值就成了 'PENDING'，和 Model::PENDING 对不上
        $body = ['`id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT'];
        foreach ($columns as $name => $type) {
            $body[] = '`' . $name . '` ' . $type;
        }
        $body[] = 'PRIMARY KEY (`id`)';

        $db->query(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $body) . ')'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach (['status', 'ip', 'mail'] as $index) {
            // MySQL 没有 CREATE INDEX IF NOT EXISTS，重复建索引会报错，直接吞掉
            try {
                $db->query('CREATE INDEX `' . $table . '_' . $index . '_idx`'
                    . ' ON `' . $table . '` (`' . $index . '`)');
            } catch (\Throwable $e) {
                // 索引已存在
            }
        }
    }

    /**
     * 表在不在
     */
    public static function exists(): bool
    {
        try {
            self::db()->fetchRow(self::db()->select()->from(self::TABLE)->limit(1));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 新申请入库
     *
     * @param array $row
     * @return int 申请 id
     * @throws DbException
     */
    public static function create(array $row): int
    {
        return (int) self::db()->query(self::db()->insert(self::TABLE)->rows($row));
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function find(int $id): ?array
    {
        return self::db()->fetchRow(
            self::db()->select()->from(self::TABLE)->where('id = ?', $id)->limit(1)
        );
    }

    /**
     * 按用户名或邮箱找最新一条申请的状态
     *
     * 给登录失败时的提示用：申请人还没有账号，核心只会说"用户名或密码无效"，
     * 这里能把"你还在等审核"这个真实原因告诉他。
     *
     * @param string $nameOrMail
     * @return string|null
     */
    public static function statusOf(string $nameOrMail): ?string
    {
        $row = self::db()->fetchRow(
            self::db()->select('status')->from(self::TABLE)
                ->where('name = ? OR mail = ?', $nameOrMail, $nameOrMail)
                ->order('id', Db::SORT_DESC)->limit(1)
        );

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * 用户名是不是已经被占用
     *
     * 已注册用户和排队中的申请都算占用 —— 不然会出现"审核通过时名字被人抢了"。
     *
     * @param string $name
     * @param int $ignore 忽略这条申请（重新提交时用）
     * @return bool
     */
    public static function nameTaken(string $name, int $ignore = 0): bool
    {
        return self::taken('name', $name, $ignore);
    }

    /**
     * 邮箱是不是已经被占用
     *
     * @param string $mail
     * @param int $ignore
     * @return bool
     */
    public static function mailTaken(string $mail, int $ignore = 0): bool
    {
        return self::taken('mail', $mail, $ignore);
    }

    /**
     * @param string $column name|mail
     * @param string $value
     * @param int $ignore
     * @return bool
     */
    private static function taken(string $column, string $value, int $ignore = 0): bool
    {
        $db = self::db();

        $user = $db->fetchRow(
            $db->select('uid')->from('table.users')->where($column . ' = ?', $value)->limit(1)
        );
        if ($user !== null) {
            return true;
        }

        $query = $db->select('id')->from(self::TABLE)
            ->where($column . ' = ?', $value)
            ->where('status = ?', self::PENDING);
        if ($ignore > 0) {
            $query->where('id <> ?', $ignore);
        }

        return $db->fetchRow($query->limit(1)) !== null;
    }

    /**
     * 同一个 IP 手上压着几条待审申请
     *
     * @param string $ip
     * @return int
     */
    public static function pendingCountByIp(string $ip): int
    {
        if ($ip === '') {
            return 0;
        }

        $row = self::db()->fetchRow(
            self::db()->select(['COUNT(id)' => 'num'])->from(self::TABLE)
                ->where('ip = ?', $ip)->where('status = ?', self::PENDING)
        );

        return $row === null ? 0 : (int) $row['num'];
    }

    /**
     * @param int $id
     * @param array $rows
     * @return int
     * @throws DbException
     */
    public static function update(int $id, array $rows): int
    {
        return (int) self::db()->query(
            self::db()->update(self::TABLE)->rows($rows)->where('id = ?', $id)
        );
    }

    /**
     * 按状态取申请列表，新的在前
     *
     * @param string|null $status null 表示全部
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function rows(?string $status, int $limit = 50, int $offset = 0): array
    {
        $query = self::db()->select()->from(self::TABLE)
            ->order('id', Db::SORT_DESC)->limit($limit)->offset($offset);

        if ($status !== null) {
            $query->where('status = ?', $status);
        }

        return self::db()->fetchAll($query);
    }

    /**
     * @param string|null $status
     * @return int
     */
    public static function count(?string $status): int
    {
        $query = self::db()->select(['COUNT(id)' => 'num'])->from(self::TABLE);
        if ($status !== null) {
            $query->where('status = ?', $status);
        }

        $row = self::db()->fetchRow($query);
        return $row === null ? 0 : (int) $row['num'];
    }

    /**
     * 一次性随机串，用来做邮件审核链接里的凭据
     *
     * 用过就换掉，所以链接天然只能用一次，也可以随时作废。
     *
     * @return string
     */
    public static function newToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return Common::randString(32);
        }
    }
}
