<?php
/**
 * openSIS Billing Module — Fee Types
 */

include __DIR__ . '/../../RedirectModulesInc.php';
include_once __DIR__ . '/../BillingFunctions.php';

DrawBC(_('Billing') . ' > ' . _('Fee Types'));

$school_id = (int)UserSchool();
$syear     = (int)UserSyear();
$action    = $_REQUEST['action'] ?? 'list';
$msg = $error = '';

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'save':
            if (empty(trim($_POST['name'] ?? ''))) {
                $error = _('Fee name is required.');
            } else {
                BillingSaveFeeType($_POST, $school_id, $syear);
                $msg    = _('Fee type saved.');
                $action = 'list';
            }
            break;
        case 'toggle':
            BillingToggleFeeType((int)$_POST['id'], $school_id);
            $action = 'list';
            break;
        case 'delete':
            $fid  = (int)$_POST['id'];
            $used = DBGet(DBQuery(
                "SELECT COUNT(*) AS cnt FROM billing_fee_assignments WHERE fee_type_id='{$fid}'"
            ));
            if ((int)($used[1]['cnt'] ?? 0) > 0) {
                $error = _('Cannot delete — fee type has student assignments. Deactivate it instead.');
            } else {
                DBQuery("DELETE FROM billing_fee_types WHERE id='{$fid}' AND school_id='{$school_id}'");
                $msg = _('Fee type deleted.');
            }
            $action = 'list';
            break;
    }
}

// Edit data
$edit = null;
if ($action === 'edit' && !empty($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $RET = DBGet(DBQuery(
        "SELECT * FROM billing_fee_types WHERE id='{$id}' AND school_id='{$school_id}'"
    ));
    $edit = $RET[1] ?? null;
}

$settings  = BillingSettings($school_id);
$cur       = htmlspecialchars($settings['currency_symbol'] ?: '$');
$fee_types = BillingGetFeeTypes($school_id, $syear, false);
$freqs     = ['once' => _('One-time'), 'monthly' => _('Monthly'), 'quarterly' => _('Quarterly'), 'annual' => _('Annual')];
$applies   = ['all' => _('All students'), 'grade' => _('By grade level'), 'student' => _('Individual')];
?>

<?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <h3 style="margin:0;"><?= _('Fee Types') ?></h3>
    <a href="Modules.php?modname=Billing/pages/FeeTypes.php&action=edit" class="btn btn-primary btn-sm">
        <i class="icon-plus2"></i> <?= _('New Fee Type') ?>
    </a>
</div>

<!-- New / Edit form -->
<?php if ($action === 'edit'): ?>
<div class="panel panel-white" style="margin-bottom:20px;">
    <div class="panel-heading">
        <h4 class="panel-title"><?= $edit ? _('Edit Fee Type') : _('New Fee Type') ?></h4>
    </div>
    <div class="panel-body">
        <form method="POST" action="Modules.php?modname=Billing/pages/FeeTypes.php&action=save">
            <?php if ($edit): ?>
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <?php endif; ?>
            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        <label><?= _('Fee Name') ?> *</label>
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               value="<?= htmlspecialchars($edit['name'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="form-group">
                        <label><?= _('Amount') ?> (<?= $cur ?>)</label>
                        <input type="number" name="amount" class="form-control" min="0" step="0.01"
                               value="<?= number_format((float)($edit['amount'] ?? 0), 2) ?>">
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="form-group">
                        <label><?= _('Frequency') ?></label>
                        <select name="frequency" class="form-control">
                            <?php foreach ($freqs as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($edit['frequency'] ?? 'once') === $v ? 'selected' : '' ?>>
                                    <?= $l ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        <label><?= _('Applies To') ?></label>
                        <select name="applies_to" class="form-control" id="applies_to_sel">
                            <?php foreach ($applies as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($edit['applies_to'] ?? 'all') === $v ? 'selected' : '' ?>>
                                    <?= $l ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-sm-2" id="grade_level_row"
                     style="<?= ($edit['applies_to'] ?? '') !== 'grade' ? 'display:none' : '' ?>">
                    <div class="form-group">
                        <label><?= _('Grade Level') ?></label>
                        <input type="text" name="grade_level" class="form-control" maxlength="10"
                               placeholder="e.g. 10"
                               value="<?= htmlspecialchars($edit['grade_level'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        <label><?= _('Description') ?></label>
                        <input type="text" name="description" class="form-control" maxlength="255"
                               value="<?= htmlspecialchars($edit['description'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><?= _('Save Fee Type') ?></button>
            <a href="Modules.php?modname=Billing/pages/FeeTypes.php" class="btn btn-default">
                <?= _('Cancel') ?>
            </a>
        </form>
    </div>
</div>
<script>
document.getElementById('applies_to_sel').addEventListener('change', function() {
    document.getElementById('grade_level_row').style.display = this.value === 'grade' ? '' : 'none';
});
</script>
<?php endif; ?>

<!-- Fee types table -->
<div class="panel panel-white">
    <div class="panel-body" style="padding:0;">
        <?php if (empty($fee_types)): ?>
            <p class="text-muted" style="padding:20px;"><?= _('No fee types yet. Create your first one above.') ?></p>
        <?php else: ?>
        <table class="table table-hover">
            <thead>
                <tr>
                    <th><?= _('Name') ?></th>
                    <th><?= _('Frequency') ?></th>
                    <th><?= _('Applies To') ?></th>
                    <th class="text-right"><?= _('Amount') ?></th>
                    <th class="text-center"><?= _('Status') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fee_types as $ft): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ft['name']) ?></strong>
                        <?php if ($ft['description']): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($ft['description']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= $freqs[$ft['frequency']] ?? $ft['frequency'] ?></td>
                    <td>
                        <?= $applies[$ft['applies_to']] ?? $ft['applies_to'] ?>
                        <?php if ($ft['grade_level']): ?>
                            <small class="text-muted">— Gr <?= htmlspecialchars($ft['grade_level']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <strong><?= $cur ?><?= number_format((float)$ft['amount'], 2) ?></strong>
                    </td>
                    <td class="text-center">
                        <span class="label <?= $ft['is_active'] ? 'label-success' : 'label-default' ?>">
                            <?= $ft['is_active'] ? _('Active') : _('Inactive') ?>
                        </span>
                    </td>
                    <td class="text-right">
                        <a href="Modules.php?modname=Billing/pages/FeeTypes.php&action=edit&id=<?= $ft['id'] ?>"
                           class="btn btn-xs btn-default"><?= _('Edit') ?></a>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $ft['id'] ?>">
                            <button type="submit" name="action" value="toggle" class="btn btn-xs btn-default">
                                <?= $ft['is_active'] ? _('Deactivate') : _('Activate') ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
