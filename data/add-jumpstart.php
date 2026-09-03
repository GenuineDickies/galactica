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
/**
 * One-shot, 2026-08-31: add the Jump Start service to the live catalog.
 *
 *   php data/add-jumpstart.php
 *
 * SVC-JUMP · "Jump Start" · $85 flat per job. Conventions (tax flag, revenue
 * account, category style) are copied from the existing SVC-SPARE row when
 * present so the catalog stays consistent with how this install was set up;
 * otherwise the seed's roadside defaults apply (revenue 4000, not taxable —
 * Oregon). Refuses to run twice: an existing jump-start item stops it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';

App::boot($cfg);
Db::boot($cfg['db']);

$dup = Db::one("SELECT sku, name, unit_price FROM catalog_items WHERE sku = 'SVC-JUMP' OR lower(name) LIKE '%jump%'");
if ($dup) {
    echo "Already there: {$dup['sku']} — {$dup['name']} at \${$dup['unit_price']}. Nothing added.\n";
    exit(0);
}

$tpl = Db::one("SELECT * FROM catalog_items WHERE sku = 'SVC-SPARE'") ?? [];

$id = Db::insert('catalog_items', [
    'sku'                  => 'SVC-JUMP',
    'item_type'            => 'SERVICE',
    'category'             => 'Jump Start',
    'service_category'     => (string) ($tpl['service_category'] ?? 'ROADSIDE') ?: 'ROADSIDE',
    'name'                 => 'Jump Start',
    'description'          => '12V battery jump start at the vehicle.',
    'pricing_model'        => 'FLAT',
    'unit_price'           => 85.00,
    'unit_cost'            => 0,
    'price_overridden'     => 0,
    'uom'                  => 'job',
    'taxable'              => (int) ($tpl['taxable'] ?? 0),
    'vehicle_not_required' => 0,
    'warranty_months'      => 0,
    'core_charge'          => 0,
    'revenue_account'      => (string) ($tpl['revenue_account'] ?? '4000') ?: '4000',
    'cogs_account'         => '',
    'is_active'            => 1,
    'is_misc'              => 0,
    'sort_order'           => 0,
]);
Audit::log('catalog_item', $id, 'created', 'SVC-JUMP — Jump Start, $85 flat (added via data/add-jumpstart.php)');
echo "Added SVC-JUMP — Jump Start, \$85.00 flat per job (revenue account "
   . ((string) ($tpl['revenue_account'] ?? '4000') ?: '4000') . ").\n";
