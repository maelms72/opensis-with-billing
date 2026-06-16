<?php
/**
 * openSIS Billing Module — Dashboard
 * Accessed via: Modules.php?modname=Billing/pages/Dashboard.php
 */

include __DIR__ . '/../../RedirectModulesInc.php';
include_once __DIR__ . '/../BillingFunctions.php';

DrawBC(_('Billing') . ' > ' . _('Dashboard'));

$school_id = (int)UserSchool();
$syear     = (int)UserSyear();
$summary   = BillingDashboardSummary($school_id, $syear);
$t         = $summary['totals'];
$settings  = BillingSettings($school_id);
$cur       = htmlspecialchars($settings['currency_symbol'] ?: '$');

$overdue = BillingGetInvoices($school_id, $syear, ['overdue' => true]);
?>

<div class="module-header">
    <h2><?= _('Billing Dashboard') ?></h2>
</div>

<!-- Metric cards -->
<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px;">
<?php
$cards = [
    [_('Total billed'),   $t['total_billed']    ?? 0, 'icon-file-text2'],
    [_('Collected'),      $t['total_collected']  ?? 0, 'icon-checkmark-circle2'],
    [_('Outstanding'),    ($t['total_billed'] - $t['total_collected']) ?? 0, 'icon-clock3'],
    [_('Overdue'),        $t['overdue_amount']   ?? 0, 'icon-warning2'],
];
foreach ($cards as [$label, $value, $icon]): ?>
    <div class="panel panel-white">
        <div class="panel-body" style="padding:14px;">
            <div style="font-size:12px;color:#888;margin-bottom:6px;">
                <i class="<?= $icon ?>" style="margin-right:4px;"></i><?= $label ?>
            </div>
            <div style="font-size:22px;font-weight:600;">
                <?= $cur ?><?= number_format((float)$value, 2) ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Monthly collections -->
<div class="panel panel-white" style="margin-bottom:20px;">
    <div class="panel-heading">
        <h4 class="panel-title"><?= _('Monthly Collections') ?> — <?= $syear ?></h4>
    </div>
    <div class="panel-body">
    <?php if (empty($summary['monthly'])): ?>
        <p class="text-muted"><?= _('No payment data for this year yet.') ?></p>
    <?php else:
        $max = max(array_column($summary['monthly'], 'collected')) ?: 1;
        $month_names = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'May','06'=>'Jun',
                        '07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'];
    ?>
        <div style="display:flex;align-items:flex-end;gap:6px;height:120px;">
        <?php foreach ($summary['monthly'] as $row):
            $pct = max(4, round(($row['collected'] / $max) * 100));
            $mon = $month_names[substr($row['month'], 5)] ?? $row['month'];
        ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;">
                <div style="font-size:9px;color:#999;"><?= $cur ?><?= number_format($row['collected'], 0) ?></div>
                <div style="width:100%;background:#337ab7;border-radius:2px 2px 0 0;height:<?= $pct ?>%;"></div>
                <div style="font-size:10px;color:#888;"><?= $mon ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- Overdue invoices -->
<?php if (!empty($overdue)): ?>
<div class="panel panel-danger" style="margin-bottom:20px;">
    <div class="panel-heading">
        <h4 class="panel-title">
            <i class="icon-warning2"></i>
            <?= count($overdue) ?> <?= _('Overdue Invoice(s)') ?>
        </h4>
    </div>
    <div class="panel-body" style="padding:0;">
        <table class="table table-hover" style="margin:0;">
            <thead>
                <tr>
                    <th><?= _('Student') ?></th>
                    <th><?= _('Invoice') ?></th>
                    <th><?= _('Due Date') ?></th>
                    <th class="text-right"><?= _('Balance') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($overdue as $inv): ?>
                <tr>
                    <td><?= htmlspecialchars($inv['student_name']) ?></td>
                    <td><code><?= htmlspecialchars($inv['invoice_number']) ?></code></td>
                    <td class="text-danger"><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
                    <td class="text-right text-danger"><strong>
                        <?= $cur ?><?= number_format((float)$inv['balance'], 2) ?>
                    </strong></td>
                    <td>
                        <a href="Modules.php?modname=Billing/pages/InvoiceView.php&invoice_id=<?= (int)$inv['id'] ?>"
                           class="btn btn-xs btn-default"><?= _('View') ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Quick actions -->
<div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="Modules.php?modname=Billing/pages/FeeTypes.php" class="btn btn-default">
        <i class="icon-price-tag"></i> <?= _('Manage Fee Types') ?>
    </a>
    <a href="Modules.php?modname=Billing/pages/Invoices.php" class="btn btn-default">
        <i class="icon-file-text2"></i> <?= _('All Invoices') ?>
    </a>
    <a href="Modules.php?modname=Billing/pages/Settings.php" class="btn btn-default">
        <i class="icon-gear"></i> <?= _('Billing Settings') ?>
    </a>
</div>
