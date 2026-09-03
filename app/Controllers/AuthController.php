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

final class AuthController
{
    public static function form(): void
    {
        if (Auth::check()) { redirect('/dashboard'); }
        View::render('pages/login', ['title' => 'Sign in', '__bare' => true]);
    }

    public static function submit(): void
    {
        if (Auth::attempt((string) input('email', ''), (string) input('password', ''))) {
            flash('Signed in. Welcome back.', 'ok');
            redirect('/dashboard');
        }
        flash('Those credentials did not match an active account.', 'err');
        redirect('/login');
    }

    public static function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}

final class DashboardController
{
    public static function index(): void
    {
        Auth::require();
        $today = date('Y-m-d');

        if (Auth::is('TECHNICIAN')) {
            $mine = Db::all(
                "SELECT w.*, e.doc_number est_no, e.service_type, e.city, e.state,
                        s.priority, c.first_name, c.last_name, c.company
                 FROM work_orders w
                 JOIN estimates e        ON e.id = w.estimate_id
                 JOIN service_requests s ON s.id = w.service_request_id
                 JOIN customers c        ON c.id = e.customer_id
                 WHERE w.technician_id = ? AND w.status NOT IN ('COMPLETED','CANCELLED','NO_SHOW')
                 ORDER BY w.id DESC",
                [Auth::id()]
            );
            View::render('pages/dashboard_tech', [
                'title' => 'My work', 'crumb' => 'Field', 'nav' => 'dashboard', 'jobs' => $mine,
            ]);
            return;
        }

        $revToday = (float) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='COMPLETED' AND substr(paid_at,1,10) = ?", [$today]
        );
        $revMonth = (float) Db::val(
            "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='COMPLETED' AND substr(paid_at,1,7) = ?", [date('Y-m')]
        );
        $ar = (float) Db::val("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status IN ('ISSUED','PARTIAL')");

        // 14-day revenue sparkline
        $spark = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $spark[] = (float) Db::val(
                "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='COMPLETED' AND substr(paid_at,1,10)=?", [$d]
            );
        }

        View::render('pages/dashboard', [
            'title' => 'Dashboard',
            'crumb' => 'Command centre',
            'nav'   => 'dashboard',
            'kpi'   => [
                'revToday'   => $revToday,
                'revMonth'   => $revMonth,
                'ar'         => $ar,
                'activeJobs' => (int) Db::val("SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('COMPLETED','CANCELLED','NO_SHOW')"),
            ],
            'spark'   => $spark,
            'intake' => Db::all(
                "SELECT * FROM service_requests WHERE status = 'PENDING' ORDER BY
                   CASE priority WHEN 'EMERGENCY' THEN 1 WHEN 'URGENT' THEN 2 WHEN 'STANDARD' THEN 3 ELSE 4 END,
                   id DESC LIMIT 10"
            ),
            'inField' => Db::all(
                "SELECT w.*, e.doc_number est_no, c.first_name, c.last_name, c.company,
                        u.first_name tech_first, u.last_name tech_last
                 FROM work_orders w
                 JOIN estimates e  ON e.id = w.estimate_id
                 JOIN customers c  ON c.id = e.customer_id
                 LEFT JOIN users u ON u.id = w.technician_id
                 /* IN_PROGRESS included: a technician actively wrenching is
                    the most in-the-field a job gets — it used to vanish from
                    this board the moment work began (fixed 2026-08-27). */
                 WHERE w.status IN ('ASSIGNED','EN_ROUTE','ON_SITE','IN_PROGRESS') ORDER BY w.id DESC LIMIT 8"
            ),
            'needsAction' => [
                'awaitingAuth' => Db::all(
                    "SELECT e.*, c.first_name, c.last_name, c.company
                     FROM estimates e JOIN customers c ON c.id = e.customer_id
                     WHERE e.authorized_at IS NULL AND e.status NOT IN ('DECLINED')
                     ORDER BY e.id DESC LIMIT 6"),
                'readyToBill' => Db::all(
                    "SELECT e.*, c.first_name, c.last_name, c.company
                     FROM estimates e JOIN customers c ON c.id = e.customer_id
                     WHERE e.status = 'APPROVED'
                       AND EXISTS (SELECT 1 FROM work_orders w WHERE w.estimate_id = e.id AND w.status IN ('COMPLETED','NO_SHOW'))
                       AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.estimate_id = e.id)
                     ORDER BY e.id DESC LIMIT 6"),
                'unpaid' => Db::all(
                    "SELECT i.*, c.first_name, c.last_name, c.company FROM invoices i
                     JOIN customers c ON c.id = i.customer_id
                     WHERE i.status IN ('ISSUED','PARTIAL') ORDER BY i.due_at LIMIT 6"),
            ],
        ]);
    }
}
