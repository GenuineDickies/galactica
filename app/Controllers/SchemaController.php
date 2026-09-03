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

/**
 * Applying a schema change to a database with no shell.
 *
 * Production is shared hosting: there is no SSH, so `php data/install.php`
 * cannot be run there, and the boot-time migrate only fires when the database
 * is completely empty. That left deployed code able to reference a column the
 * live database did not have — which is not a degraded feature, it is an
 * exception on the next insert and a broken intake form.
 *
 * This is the missing step. It shows the pending change first and applies it
 * only when an admin says so. Db::migrate() is additive by construction — it
 * CREATEs missing tables and ALTER-ADDs missing columns, and never drops,
 * renames or retypes anything — so the worst case is a column nothing uses yet.
 *
 * Deliberately ADMIN-only rather than ADMIN+DISPATCH: dispatchers run jobs,
 * they do not run DDL.
 */
final class SchemaController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN');

        $pending = Db::pending();
        View::render('pages/schema', [
            'title'   => 'Database schema',
            'crumb'   => 'Admin',
            'nav'     => 'settings',
            'pending' => $pending,
            'driver'  => Db::driver(),
            'columns' => array_sum(array_map('count', $pending)),
            'tables'  => array_keys(array_filter($pending, static fn($c) => $c === [])),
            /* Indexes were invisible here until an index added to an existing
             * table turned out never to have been created at all. A missing
             * index has no symptom except a query quietly going full-scan, so
             * it has to be shown rather than inferred. */
            'indexes' => Db::pendingIndexes(),
        ]);
    }

    /**
     * Apply it. The before/after difference is what gets reported and audited,
     * rather than a bare "done" — an operator needs to see that the column they
     * were waiting for is the column that landed.
     */
    public static function apply(): void
    {
        Auth::requireRole('ADMIN');

        $before   = Db::pending();
        $beforeIx = Db::pendingIndexes();
        if ($before === [] && $beforeIx === []) {
            flash('The schema was already up to date. Nothing was changed.', 'ok');
            redirect('/admin/schema');
        }

        try {
            Db::migrate();
        } catch (Throwable $e) {
            Audit::log('system', 0, 'schema:failed', substr($e->getMessage(), 0, 400));
            flash('The schema change did not complete: ' . e($e->getMessage())
                . ' Nothing else was attempted.', 'err');
            redirect('/admin/schema');
        }

        $after   = Db::pending();
        $applied = [];
        foreach ($before as $table => $cols) {
            $still = $after[$table] ?? [];
            $done  = $cols === [] ? ['(table created)'] : array_keys(array_diff_key($cols, $still));
            if ($done) { $applied[] = $table . ': ' . implode(', ', $done); }
        }

        $afterIx = Db::pendingIndexes();
        $madeIx  = count($beforeIx) - count($afterIx);
        if ($madeIx > 0) { $applied[] = $madeIx . ' index' . ($madeIx === 1 ? '' : 'es'); }

        $detail = $applied ? implode(' · ', $applied) : 'nothing changed';
        Audit::log('system', 0, 'schema:migrated', substr($detail, 0, 400));

        if ($after !== [] || $afterIx !== []) {
            flash('Applied ' . e($detail) . ' — but some changes are still pending. '
                . 'Check the list below.', 'warn');
        } else {
            flash('Schema is now up to date. Applied ' . e($detail) . '.', 'ok');
        }
        redirect('/admin/schema');
    }
}
