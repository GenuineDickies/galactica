<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;
    private static array $cfg = [];

    public static function boot(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function driver(): string
    {
        return self::$cfg['driver'] ?? 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = self::$cfg;
        if (($c['driver'] ?? 'sqlite') === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']);
            $pdo = new PDO($dsn, $c['username'], $c['password']);
        } else {
            $dir = dirname($c['path']);
            if (!is_dir($dir)) { mkdir($dir, 0775, true); }
            $pdo = new PDO('sqlite:' . $c['path']);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return self::$pdo = $pdo;
    }

    public static function q(string $sql, array $args = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $args = []): array
    {
        return self::q($sql, $args)->fetchAll();
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::q($sql, $args)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $args = [], mixed $default = null): mixed
    {
        $r = self::q($sql, $args)->fetch(PDO::FETCH_NUM);
        return $r === false ? $default : ($r[0] ?? $default);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' .
                implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
        self::q($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): void
    {
        if (!$data) { return; }
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($data)));
        $data['__id'] = $id;
        self::q("UPDATE $table SET $set WHERE id = :__id", $data);
    }

    /**
     * Run a callable in a transaction. RE-ENTRANT: a tx() inside a tx() joins
     * the outer one rather than starting a second.
     *
     * PDO has no nested transactions — a second beginTransaction() throws. That
     * became a problem the moment the ledger arrived: issuing an invoice is
     * already wrapped in tx(), and the journal entry it raises must commit or
     * roll back with it, so Ledger::post() has to be callable from inside.
     * Without this counter the choice would be posting outside the transaction
     * — where a failure leaves an issued invoice with no entry behind it, which
     * is the exact silent-hole the ledger exists to prevent.
     *
     * The inner call does not commit. Only the outermost does, so the whole
     * nest is still one atomic unit.
     */
    private static int $txDepth = 0;

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$txDepth > 0) {
            self::$txDepth++;
            try { return $fn(); }
            finally { self::$txDepth--; }
        }

        $pdo->beginTransaction();
        self::$txDepth = 1;
        try {
            $out = $fn();
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            self::$txDepth = 0;
        }
    }

    public static function migrate(): void
    {
        foreach (Schema::statements(self::driver()) as $sql) {
            self::pdo()->exec($sql);
        }
        self::addMissingColumns();
        self::addMissingIndexes();
    }

    /**
     * Additive schema evolution. Any column present in Schema.php but absent
     * from the live table is ALTER-added; nothing is ever dropped, renamed or
     * retyped. Safe against any database, and a no-op when nothing is missing —
     * which is what lets install.php and wipe.php run it unconditionally.
     */
    private static function addMissingColumns(): void
    {
        foreach (self::pending() as $table => $cols) {
            if ($cols === []) { continue; }   // table itself missing: CREATE above owns that
            foreach ($cols as $name => $decl) {
                self::pdo()->exec("ALTER TABLE $table ADD COLUMN $name $decl");
            }
        }
    }

    /**
     * Create any index declared in Schema.php that the live database lacks.
     *
     * THE BUG THIS FIXES. Indexes reach a database through two different
     * routes: on SQLite as separate CREATE INDEX statements, on MySQL folded
     * into the CREATE TABLE body — because MySQL has no CREATE INDEX IF NOT
     * EXISTS and would fail on a second run. Both routes only fire when the
     * TABLE is created. So an index added to Schema.php for a table that
     * already existed was never created on MySQL, silently, and the only
     * symptom was a query quietly going full-scan.
     *
     * addMissingColumns had the same shape of problem solved for columns and
     * this was simply never done for indexes. Additive in the same sense:
     * an index that exists is left alone, nothing is ever dropped.
     */
    private static function addMissingIndexes(): void
    {
        foreach (self::pendingIndexes() as [$name, $table, $cols, $unique]) {
            $kind = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
            try {
                self::pdo()->exec("$kind $name ON $table($cols)");
            } catch (Throwable) {
                /* A duplicate-key failure means the data cannot support a
                 * UNIQUE index — real information, but not a reason to abort
                 * a migration that has already added tables and columns. It
                 * stays pending and stays visible on the schema page. */
            }
        }
    }

    /**
     * Indexes the code declares that the database does not have.
     *
     * @return array<int,array{0:string,1:string,2:string,3:bool}> [name, table, cols, unique]
     */
    public static function pendingIndexes(): array
    {
        $out = [];
        foreach (Schema::indexes(self::driver()) as [$name, $table, $cols, $unique]) {
            if (!self::liveColumns($table)) { continue; }   // table absent: CREATE owns it
            if (in_array($name, self::liveIndexes($table), true)) { continue; }
            $out[] = [$name, $table, $cols, $unique];
        }
        return $out;
    }

    /**
     * Does this table exist yet?
     *
     * Production applies schema changes by hand, so deployed code routinely
     * runs for a while against a database that has not caught up. A page that
     * throws in that window renders a blank 500 and looks like a broken
     * application rather than one step ahead of its schema.
     */
    public static function tableExists(string $table): bool
    {
        return self::liveColumns($table) !== [];
    }

    /** Index names a table actually carries right now. */
    private static function liveIndexes(string $table): array
    {
        try {
            if (self::driver() === 'mysql') {
                return array_values(array_unique(array_column(self::all(
                    'SELECT DISTINCT index_name AS name FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ?', [$table]), 'name')));
            }
            return array_column(self::all("PRAGMA index_list($table)"), 'name');
        } catch (Throwable) {
            return [];
        }
    }

    /** The column names a table actually has right now. [] when it does not exist. */
    private static function liveColumns(string $table): array
    {
        try {
            return self::driver() === 'mysql'
                ? array_column(self::all(
                    'SELECT column_name AS name FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ?', [$table]), 'name')
                : array_column(self::all("PRAGMA table_info($table)"), 'name');
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Exactly what migrate() would add, without adding it.
     *
     * The read-only half of addMissingColumns, so an operator can be shown the
     * pending change before authorising it. Production has no shell, so the
     * only way to apply a schema change there is through the admin page — and
     * a page that runs DDL without first saying what it will do is not one
     * anybody should press.
     *
     * @return array<string, array<string,string>> table => [column => declaration].
     *         A table present here with an EMPTY array does not exist at all.
     */
    public static function pending(): array
    {
        $out = [];
        foreach (Schema::columns(self::driver()) as $table => $cols) {
            $live = self::liveColumns($table);
            if (!$live) { $out[$table] = []; continue; }
            $missing = [];
            foreach ($cols as $name => $decl) {
                if (!in_array($name, $live, true)) { $missing[$name] = $decl; }
            }
            if ($missing) { $out[$table] = $missing; }
        }
        return $out;
    }
}
