<?php
/**
 * openSIS Billing Module — Settings
 */

include __DIR__ . '/../../RedirectModulesInc.php';
include_once __DIR__ . '/../BillingFunctions.php';

DrawBC('Billing > Settings');

$school_id = (int)UserSchool();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    BillingSaveSettings($school_id, $_POST);
    $msg = _('Settings saved.');
}

$s = BillingSettings($school_id);
?>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<h3><?= _('Billing Settings') ?></h3>

<div class="panel panel-white" style="max-width:600px;">
  <div class="panel-body">
    <form method="POST">

        <h5 class="text-uppercase text-muted" style="margin-bottom:10px;font-size:11px;">
            <?= _('Invoicing') ?>
        </h5>
        <div class="row">
            <div class="col-sm-4">
                <div class="form-group">
                    <label><?= _('Invoice Prefix') ?></label>
                    <input type="text" name="invoice_prefix" class="form-control" maxlength="10"
                           value="<?= htmlspecialchars($s['invoice_prefix']) ?>">
                    <p class="help-block"><?= _('e.g. INV → INV-2026-00001') ?></p>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="form-group">
                    <label><?= _('Default Due Days') ?></label>
                    <input type="number" name="default_due_days" class="form-control" min="1" max="365"
                           value="<?= (int)$s['default_due_days'] ?>">
                </div>
            </div>
        </div>

        <h5 class="text-uppercase text-muted" style="margin-bottom:10px;font-size:11px;">
            <?= _('Currency & Tax') ?>
        </h5>
        <div class="row">
            <div class="col-sm-3">
                <div class="form-group">
                    <label><?= _('Currency Symbol') ?></label>
                    <input type="text" name="currency_symbol" class="form-control" maxlength="5"
                           value="<?= htmlspecialchars($s['currency_symbol']) ?>">
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    <label><?= _('Currency Code') ?></label>
                    <input type="text" name="currency_code" class="form-control" maxlength="3"
                           value="<?= htmlspecialchars($s['currency_code']) ?>">
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    <label><?= _('Tax Rate (%)') ?></label>
                    <input type="number" name="tax_rate" class="form-control" min="0" max="100" step="0.01"
                           value="<?= number_format((float)$s['tax_rate'],2) ?>">
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    <label><?= _('Tax Label') ?></label>
                    <input type="text" name="tax_label" class="form-control" maxlength="30"
                           value="<?= htmlspecialchars($s['tax_label']) ?>">
                </div>
            </div>
        </div>

        <h5 class="text-uppercase text-muted" style="margin-bottom:10px;font-size:11px;">
            <?= _('Invoice Footer') ?>
        </h5>
        <div class="form-group">
            <textarea name="invoice_footer" class="form-control" rows="3"
                      placeholder="<?= _('Payment terms, bank details, thank you message...') ?>"><?= htmlspecialchars($s['invoice_footer'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <div class="checkbox">
                <label>
                    <input type="checkbox" name="email_on_generate"
                           <?= $s['email_on_generate'] ? 'checked' : '' ?>>
                    <?= _('Email invoice to student/parent when generated') ?>
                </label>
                <p class="help-block" style="margin-left:20px;">
                    <?= _('Requires SMTP to be configured in openSIS system settings.') ?>
                </p>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><?= _('Save Settings') ?></button>
    </form>
  </div>
</div>
