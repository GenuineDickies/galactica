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
 * The local checkout page.
 *
 * Public, because the customer opening it is not a user of this system — they
 * have a link from a text message and nothing else. Access is the unguessable
 * token in the URL, and the page exposes only what a payer needs to see: who is
 * charging, what for, and how much.
 *
 * This is what a payment link points at when no processor is configured. It
 * records money through the same path a Square callback does, so the flow that
 * gets exercised here is the flow that runs in production — not a rehearsal of
 * it. When Square is configured, links point at Square instead and this route
 * refuses to take anything, so it can never become a way to mark a real invoice
 * paid without a real payment.
 */
final class CheckoutController
{
    public static function show(array $a): void
    {
        [$link, $inv] = self::resolve((string) $a['token']);

        View::render('pages/checkout', [
            'title'    => 'Pay ' . $inv['doc_number'],
            '__bare'   => true,
            'link'     => $link,
            'inv'      => $inv,
            'customer' => Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]),
            'lines'    => Lines::forDoc('INV', (int) $inv['id']),
            'simulated'=> true,
        ]);
    }

    public static function pay(array $a): void
    {
        [$link, $inv] = self::resolve((string) $a['token']);

        if ($link['status'] !== 'OPEN') {
            flash('That payment link has already been used — each one works exactly once. If you still owe a balance, reply to our text or give us a call and we\'ll send a fresh link.', 'warn');
            redirect('/pay/' . $a['token']);
        }

        $amount = min((float) $link['amount'], (float) $inv['balance_due']);
        if ($amount <= 0) {
            flash('This invoice is already settled. Nothing was charged.', 'warn');
            redirect('/pay/' . $a['token']);
        }

        $written = PaymentController::record($inv, $amount, 'CARD', [
            'processor'     => 'local-checkout',
            'processor_ref' => 'LOCAL-' . $link['order_id'],
            'reference'     => (string) $link['order_id'],
            // The page offers fixed tip amounts; the server accepts only those.
            // Anything else — including a number typed into a crafted request —
            // is no tip, never a phantom cash receipt in the books.
            'tip'           => in_array(num('tip_amount'), [0.0, 5.0, 10.0, 20.0], true) ? num('tip_amount') : 0.0,
            'note'          => 'Paid on the checkout page',
        ]);

        if (!$written) {
            flash('That payment link has already been used — each one works exactly once. If you still owe a balance, reply to our text or give us a call and we\'ll send a fresh link.', 'warn');
            redirect('/pay/' . $a['token']);
        }

        Db::update('payment_links', (int) $link['id'], ['status' => 'PAID']);
        Audit::log('invoice', (int) $inv['id'], 'payment:checkout', money($amount) . ' via the checkout page');

        flash('Payment received — thank you. A receipt has been issued.', 'ok');
        redirect('/pay/' . $a['token']);
    }

    /**
     * @return array{0:array,1:array}
     */
    private static function resolve(string $token): array
    {
        $link = Db::one('SELECT * FROM payment_links WHERE order_id = ?', [$token]);

        // A processor-backed install must never accept money here.
        if (!$link || $link['provider'] !== 'manual' || !str_starts_with($token, 'sim_')) {
            http_response_code(404);
            View::render('pages/404', ['title' => 'Not found', '__bare' => true]);
            exit;
        }

        $inv = Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $link['invoice_id']]);
        if (!$inv) {
            http_response_code(404);
            View::render('pages/404', ['title' => 'Not found', '__bare' => true]);
            exit;
        }
        return [$link, $inv];
    }
}
