<?php
/**
 * openSIS Billing Module — Invoice List & Generator
 */

include __DIR__ . '/../../RedirectModulesInc.php';
include_once __DIR__ . '/../BillingFunctions.php';

DrawBC(_('Billing') . ' > ' . _('Invoices'));

$school_id = (int)UserSchool();
$syear     = (int)UserSyear();
$action    = $_REQUEST['action'] ?? 'list';
$msg = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'generate':
            try {
                $inv_id = BillingGenerateInvoice((int)$_POST['student_id'], $school_id, $syear);
                header("Location: Modules.php?modname=Billing/pages/InvoiceView.php&invoice_id={$inv_id}&generated=1");
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            break;
        case 'void':
            $iid = (int)$_POST['invoice_id'];
            DBQuery("UPDATE billing_invoices SET status='void'
                     WHERE id='{$iid}' AND school_id='{$school_id}' AND status NOT IN ('paid')");
            $msg = _('Invoice voided.');
            break;
        case 'markSent':
            $iid = (int)$_POST['invoice_id'];
            DBQuery("UPDATE billing_invoices SET status='sent'
                     WHERE id='{$iid}' AND school_id='{$school_id}' AND status='draft'");
            $msg = _('Invoice marked as sent.');
            break;
    }
}

$filter_status = $_GET['status'] ?? '';
$filters = [];
if ($filter_status) $filters['status'] = $filter_status;

$invoices  = BillingGetInvoices($school_id, $syear, $filters);
$settings  = BillingSettings($school_id);
$cur       = htmlspecialchars($settings['currency_symbol'] ?: '$');

$status_labels = [
    'draft'   => ['Draft',    'label-default'],
    'sent'    => ['Sent',     'label-info'],
    'partial' => ['Partial',  'label-warning'],
    'paid'    => ['Paid',     'label-success'],
    'overdue' => ['Overdue',  'label-danger'],
    'void'    => ['Void',     'label-default'],
];
?>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <h3 style="margin:0;"><?= _('Invoices') ?></h3>
    <button class="btn btn-primary btn-sm" type="button"
            onclick="$('#gen-panel').toggleClass('hidden')">
        <i class="icon-plus2"></i> <?= _('Generate Invoice') ?>
    </button>
</div>

<!-- Generate panel -->
<div id="gen-panel" class="panel panel-white hidden" style="margin-bottom:20px;">
    <div class="panel-heading"><h4 class="panel-title"><?= _('Generate Invoice for Student') ?></h4></div>
    <div class="panel-body">
        <p class="text-muted"><?= _('Creates an invoice from the student\'s active fee assignments.') ?></p>
        <form method="POST" action="Modules.php?modname=Billing/pages/Invoices.php&action=generate"
              class="form-inline">
            <?php
            $students_RET = DBGet(DBQuery(
                "SELECT STUDENT_ID, CONCAT(FIRST_NAME,' ',LAST_NAME) AS full_name, GRADE
                 FROM students
                 WHERE SCHOOL_ID='{$school_id}' AND SYEAR='{$syear}'
                   AND (ENROLLMENT_CODE IS NULL OR ENROLLMENT_CODE NOT IN ('G','T'))
                 ORDER BY LAST_NAME, FIRST_NAME"
            ));
            ?>
            <div class="form-group" style="margin-right:10px;">
                <select name="student_id" class="form-control" required>
                    <option value=""><?= _('— select student —') ?></option>
                    <?php foreach (($students_RET ?: []) as $s): ?>
                        <option value="<?= (int)$s['STUDENT_ID'] ?>">
                            <?= htmlspecialchars($s['full_name']) ?> (<?= _('Gr') ?> <?= $s['GRADE'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><?= _('Generate') ?></button>
        </form>
    </div>
</div>

<!-- Status filter tabs -->
<ul class="nav nav-pills" style="margin-bottom:15px;">
    <?php
    $tabs = ['' => _('All'), 'draft' => _('Draft'), 'sent' => _('Sent'), 'partial' => _('Partial'),
             'paid' => _('Paid'), 'overdue' => _('Overdue'), 'void' => _('Void')];
    foreach ($tabs as $val => $label): ?>
        <li class="<?= $filter_status === $val ? 'active' : '' ?>">
            <a href="Modules.php?modname=Billing/pages/Invoices.php<?= $val ? '&status='.$val : '' ?>">
                <?= $label ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<!-- Invoices table -->
<div class="panel panel-white">
    <div class="panel-body" style="padding:0;">
    <?php if (empty($invoices)): ?>
        <p class="text-muted" style="padding:20px;">
            <?= _('No invoices found') ?><?= $filter_status ? ' — ' . $filter_status : '' ?>.
        </p>
    <?php else: ?>
        <table class="table table-hover" style="table-layout:fixed;">
            <thead>
                <tr>
                    <th style="width:160px;"><?= _('Invoice #') ?></th>
                    <th><?= _('Student') ?></th>
                    <th style="width:100px;"><?= _('Issued') ?></th>
                    <th style="width:100px;"><?= _('Due') ?></th>
                    <th style="width:90px;text-align:right;"><?= _('Total') ?></th>
                    <th style="width:90px;text-align:right;"><?= _('Balance') ?></th>
                    <th style="width:80px;text-align:center;"><?= _('Status') ?></th>
                    <th style="width:60px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv):
                [$slabel, $sclass] = $status_labels[$inv['status']] ?? [ucfirst($inv['status']), 'label-default'];
            ?>
                <tr>
                    <td><code style="font-size:11px;"><?= htmlspecialchars($inv['invoice_number']) ?></code></td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($inv['student_name']) ?>
                        <small class="text-muted">Gr<?= $inv['grade'] ?></small>
                    </td>
                    <td><?= date('d M Y', strtotime($inv['issue_date'])) ?></td>
                    <td class="<?= $inv['status'] === 'overdue' ? 'text-danger' : '' ?>">
                        <?= date('d M Y', strtotime($inv['due_date'])) ?>
                    </td>
                    <td class="text-right"><?= $cur ?><?= number_format((float)$inv['total'], 2) ?></td>
                    <td class="text-right">
                        <strong><?= $cur ?><?= number_format((float)$inv['balance'], 2) ?></strong>
                    </td>
                    <td class="text-center">
                        <span class="label <?= $sclass ?>"><?= $slabel ?></span>
                    </td>
                    <td>
                        <a href="Modules.php?modname=Billing/pages/InvoiceView.php&invoice_id=<?= (int)$inv['id'] ?>"
                           class="btn btn-xs btn-default"><?= _('View') ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</div>
