<?php
/**
 * openSIS Billing Module — Invoice View & Payment Recording
 */

include '../../RedirectModulesInc.php';
include_once '../BillingFunctions.php';

$school_id  = (int)UserSchool();
$syear      = (int)UserSyear();
$invoice_id = (int)($_REQUEST['invoice_id'] ?? 0);
$msg = $error = '';

DrawBC(_('Billing') . ' > ' . _('Invoices') . ' > ' . _('View Invoice'));

if (!$invoice_id) {
    echo '<div class="alert alert-danger">' . _('Invalid invoice ID.') . '</div>';
    return;
}

// Handle payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $inv = BillingGetInvoice($invoice_id, $school_id);
    $amt = (float)$_POST['amount'];
    if (!$inv) {
        $error = _('Invoice not found.');
    } elseif ($amt <= 0) {
        $error = _('Amount must be greater than zero.');
    } elseif ($amt > (float)$inv['balance']) {
        $error = _('Amount exceeds outstanding balance.');
    } else {
        BillingRecordPayment([
            'invoice_id'   => $invoice_id,
            'student_id'   => $inv['student_id'],
            'amount'       => $amt,
            'payment_date' => $_POST['payment_date'] ?: date('Y-m-d'),
            'method'       => $_POST['method'],
            'reference'    => $_POST['reference'] ?? '',
            'note'         => $_POST['note'] ?? '',
        ], $school_id);
        $msg = _('Payment recorded successfully.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    $iid = (int)$_POST['invoice_id'];
    DBQuery("UPDATE billing_invoices SET status='void'
             WHERE id='{$iid}' AND school_id='{$school_id}' AND status NOT IN ('paid')");
    $msg = _('Invoice voided.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $iid = (int)$_POST['invoice_id'];
    DBQuery("UPDATE billing_invoices SET status='sent'
             WHERE id='{$iid}' AND school_id='{$school_id}' AND status='draft'");
    $msg = _('Invoice marked as sent.');
}

$inv      = BillingGetInvoice($invoice_id, $school_id);
$settings = BillingSettings($school_id);
$cur      = htmlspecialchars($settings['currency_symbol'] ?: '$');

if (!$inv) {
    echo '<div class="alert alert-danger">' . _('Invoice not found.') . '</div>';
    return;
}

if (!empty($_GET['generated'])) {
    $msg = sprintf(_('Invoice %s created successfully.'), $inv['invoice_number']);
}

$status_map = [
    'draft'   => 'label-default', 'sent'    => 'label-info',
    'partial' => 'label-warning', 'paid'    => 'label-success',
    'overdue' => 'label-danger',  'void'    => 'label-default',
];
$sclass = $status_map[$inv['status']] ?? 'label-default';
$methods = ['cash'=>_('Cash'),'card'=>_('Card'),'bank_transfer'=>_('Bank Transfer'),
            'cheque'=>_('Cheque'),'online'=>_('Online'),'other'=>_('Other')];
?>

<p>
    <a href="Modules.php?modname=Billing/pages/Invoices.php">&larr; <?= _('Back to Invoices') ?></a>
</p>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row">
<div class="col-sm-8">
<div class="panel panel-white">
  <div class="panel-body">

    <!-- Invoice header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
        <div>
            <h3 style="margin:0 0 6px;"><?= htmlspecialchars($inv['invoice_number']) ?></h3>
            <span class="label <?= $sclass ?>"><?= ucfirst($inv['status']) ?></span>
        </div>
        <div class="text-right text-muted" style="font-size:13px;">
            <div><?= _('Issued:') ?> <?= date('d M Y', strtotime($inv['issue_date'])) ?></div>
            <div class="<?= $inv['status']==='overdue' ? 'text-danger' : '' ?>">
                <?= _('Due:') ?> <?= date('d M Y', strtotime($inv['due_date'])) ?>
            </div>
        </div>
    </div>

    <!-- Bill to -->
    <div style="background:#f7f7f7;border-radius:4px;padding:12px;margin-bottom:20px;">
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#999;margin-bottom:4px;">
            <?= _('Bill To') ?>
        </div>
        <strong><?= htmlspecialchars($inv['student_name']) ?></strong><br>
        <span class="text-muted" style="font-size:12px;">
            <?= _('Student ID:') ?> <?= htmlspecialchars($inv['student_number']) ?>
            &bull; <?= _('Grade') ?> <?= $inv['grade'] ?>
        </span>
    </div>

    <!-- Line items -->
    <table class="table table-condensed">
        <thead>
            <tr>
                <th><?= _('Description') ?></th>
                <th class="text-right" style="width:60px;"><?= _('Qty') ?></th>
                <th class="text-right" style="width:90px;"><?= _('Unit') ?></th>
                <th class="text-right" style="width:90px;"><?= _('Total') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($inv['items'] as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['description']) ?></td>
                <td class="text-right text-muted"><?= number_format((float)$item['quantity'], 2) ?></td>
                <td class="text-right text-muted"><?= $cur ?><?= number_format((float)$item['unit_amount'], 2) ?></td>
                <td class="text-right"><?= $cur ?><?= number_format((float)$item['line_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <table style="width:240px;margin-left:auto;font-size:13px;" class="table table-condensed">
        <tr><td class="text-muted"><?= _('Subtotal') ?></td>
            <td class="text-right"><?= $cur ?><?= number_format((float)$inv['subtotal'],2) ?></td></tr>
        <?php if ($inv['discount_total'] > 0): ?>
        <tr><td class="text-muted"><?= _('Discounts') ?></td>
            <td class="text-right text-success">−<?= $cur ?><?= number_format((float)$inv['discount_total'],2) ?></td></tr>
        <?php endif; ?>
        <?php if ($inv['tax_total'] > 0): ?>
        <tr><td class="text-muted"><?= htmlspecialchars($settings['tax_label']) ?></td>
            <td class="text-right"><?= $cur ?><?= number_format((float)$inv['tax_total'],2) ?></td></tr>
        <?php endif; ?>
        <tr style="font-size:15px;font-weight:bold;border-top:1px solid #ddd;">
            <td><?= _('Total') ?></td>
            <td class="text-right"><?= $cur ?><?= number_format((float)$inv['total'],2) ?></td></tr>
        <tr><td class="text-success"><?= _('Paid') ?></td>
            <td class="text-right text-success"><?= $cur ?><?= number_format((float)$inv['amount_paid'],2) ?></td></tr>
        <tr style="font-size:15px;font-weight:bold;">
            <td><?= _('Balance Due') ?></td>
            <td class="text-right <?= $inv['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                <?= $cur ?><?= number_format((float)$inv['balance'],2) ?>
            </td></tr>
    </table>

    <!-- Payment history -->
    <?php if (!empty($inv['payments'])): ?>
    <div style="margin-top:20px;border-top:1px solid #eee;padding-top:15px;">
        <h5><?= _('Payment History') ?></h5>
        <table class="table table-condensed" style="font-size:12px;">
            <?php foreach ($inv['payments'] as $p): ?>
            <tr>
                <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                <td class="text-muted">
                    <?= $methods[$p['method']] ?? $p['method'] ?>
                    <?php if ($p['reference']): ?>
                        · <code><?= htmlspecialchars($p['reference']) ?></code>
                    <?php endif; ?>
                </td>
                <td class="text-right text-success">
                    +<?= $cur ?><?= number_format((float)$p['amount'],2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($settings['invoice_footer'])): ?>
    <div style="margin-top:20px;border-top:1px solid #eee;padding-top:10px;font-size:11px;color:#aaa;">
        <?= nl2br(htmlspecialchars($settings['invoice_footer'])) ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</div><!-- col-sm-8 -->

<div class="col-sm-4">

    <!-- Actions -->
    <div class="panel panel-white" style="margin-bottom:15px;">
        <div class="panel-heading"><h4 class="panel-title"><?= _('Actions') ?></h4></div>
        <div class="panel-body">
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php if ($inv['status'] === 'draft'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="icon-paper-plane"></i> <?= _('Mark as Sent') ?>
                    </button>
                </form>
                <?php endif; ?>
                <a href="Modules.php?modname=Billing/pages/InvoicePrint.php&invoice_id=<?= $invoice_id ?>"
                   target="_blank" class="btn btn-default btn-block">
                    <i class="icon-printer"></i> <?= _('Print / PDF') ?>
                </a>
                <?php if (!in_array($inv['status'], ['paid','void'])): ?>
                <form method="POST" onsubmit="return confirm('<?= _('Void this invoice?') ?>')">
                    <input type="hidden" name="action" value="void">
                    <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                    <button type="submit" class="btn btn-danger btn-block">
                        <i class="icon-blocked"></i> <?= _('Void Invoice') ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Record payment -->
    <?php if (!in_array($inv['status'], ['paid','void']) && $inv['balance'] > 0): ?>
    <div class="panel panel-white">
        <div class="panel-heading"><h4 class="panel-title"><?= _('Record Payment') ?></h4></div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="pay">
                <div class="form-group">
                    <label><?= _('Amount') ?> (max <?= $cur ?><?= number_format((float)$inv['balance'],2) ?>)</label>
                    <input type="number" name="amount" class="form-control" min="0.01" step="0.01"
                           max="<?= number_format((float)$inv['balance'],2,'.','') ?>"
                           value="<?= number_format((float)$inv['balance'],2,'.','') ?>" required>
                </div>
                <div class="form-group">
                    <label><?= _('Payment Date') ?></label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label><?= _('Method') ?></label>
                    <select name="method" class="form-control">
                        <?php foreach ($methods as $v => $l): ?>
                            <option value="<?= $v ?>"><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?= _('Reference / Receipt #') ?></label>
                    <input type="text" name="reference" class="form-control" maxlength="100">
                </div>
                <button type="submit" class="btn btn-success btn-block">
                    <i class="icon-checkmark"></i> <?= _('Record Payment') ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div><!-- col-sm-4 -->
</div><!-- row -->
