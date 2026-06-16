<?php
/**
 * Billing module menu registration.
 * Uses the same pattern as other openSIS module Menu.php files:
 * populate $menu['modcat']['profile'] with 'path.php' => 'Label' pairs.
 */
include('../../RedirectModulesInc.php');

$menu['billing']['admin'] = array(
    'billing/Dashboard.php'  => 'Dashboard',
    'billing/FeeTypes.php'   => 'Fee Types',
    'billing/Invoices.php'   => 'Invoices',
    'billing/Payments.php'   => 'Payments',
    'billing/Settings.php'   => 'Settings',
);
