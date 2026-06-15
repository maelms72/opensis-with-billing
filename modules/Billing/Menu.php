<?php
/**
 * openSIS Billing Module — Menu Registration
 *
 * openSIS loads modules/$module/Menu.php for every entry in $openSISModules.
 * This file registers billing's menu items into the $_openSIS['Menu'] array
 * which Modules.php uses to build the top navigation.
 */

// Only show billing menu to admin and teacher profiles (not students/parents)
$profile = User('PROFILE');
if ($profile === 'student' || $profile === 'parent') {
    return;
}

$_openSIS['Menu']['billing'] = [
    'Dashboard.php'    => _('Dashboard'),
    'FeeTypes.php'     => _('Fee Types'),
    'Invoices.php'     => _('Invoices'),
    'Payments.php'     => _('Payments'),
    'Settings.php'     => _('Settings'),
];

// Register menu category label
$_openSIS['MenuLabel']['billing'] = _('Billing');
