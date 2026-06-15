<?php
/**
 * openSIS Billing Module — Printable Invoice
 * Opens in new tab, triggers browser print dialog.
 */

// Load openSIS bootstrap (for DB functions + session)
require_once '../../../DatabaseInc.php';
require_once '../../../functions/UserFnc.php';
require_once '../BillingFunctions.php';

// Must be logged in
if (!$_SESSION['STAFF_ID'] && !$_SESSION['STUDENT_ID']) {
    header('Location: ../../../index.php');
    exit;
}

$school_id  = (int)UserSchool();
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$inv        = BillingGetInvoice($invoice_id, $school_id);
$settings   = BillingSettings($school_id);

if (!$inv) { die('Invoice not found.'); }

$cur = htmlspecialchars($settings['currency_symbol'] ?: '$');

$school_RET = DBGet(DBQuery(
    "SELECT TITLE, ADDRESS, CITY, STATE, ZIPCODE, PHONE FROM schools WHERE ID='{$school_id}'"
));
$school = $school_RET[1] ?? [];

$methods = ['cash'=>'Cash','card'=>'Card','bank_transfer'=>'Bank Transfer',
            'cheque'=>'Cheque','online'=>'Online','other'=>'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 13px; color: #222; background: #fff; padding: 40px; }
  .header { display: flex; justify-content: space-between; margin-bottom: 30px; }
  .school-name { font-size: 20px; font-weight: bold; margin-bottom: 4px; }
  .inv-number { font-size: 22px; font-weight: bold; }
  .label { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; }
  .label-paid    { background: #dff0d8; color: #3c763d; }
  .label-draft   { background: #f5f5f5; color: #777; }
  .label-sent    { background: #d9edf7; color: #31708f; }
  .label-partial { background: #fcf8e3; color: #8a6d3b; }
  .label-overdue { background: #f2dede; color: #a94442; }
  .bill-to { background: #f7f7f7; border-radius: 4px; padding: 12px; margin-bottom: 24px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; padding: 8px 4px; border-bottom: 1px solid #ddd; font-size: 12px; color: #666; }
  td { padding: 9px 4px; border-bottom: 1px solid #eee; }
  .text-right { text-align: right; }
  .totals { width: 220px; margin-left: auto; margin-top: 16px; }
  .totals td { padding: 3px 4px; }
  .total-row td { font-weight: bold; font-size: 15px; border-top: 1px solid #ccc; padding-top: 6px; }
  .footer-text { margin-top: 30px; padding-top: 12px; border-top: 1px solid #eee; font-size: 11px; color: #999; }
  @media print { body { padding: 0; } @page { margin: 1.5cm; } }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="school-name"><?= htmlspecialchars($school['TITLE'] ?? 'School') ?></div>
    <div style="font-size:12px;color:#666;line-height:1.6;">
      <?= htmlspecialchars($school['ADDRESS'] ?? '') ?><br>
      <?= htmlspecialchars($school['CITY'] ?? '') ?>, <?= htmlspecialchars($school['STATE'] ?? '') ?>
      <?= htmlspecialchars($school['ZIPCODE'] ?? '') ?><br>
      <?= htmlspecialchars($school['PHONE'] ?? '') ?>
    </div>
  </div>
  <div style="text-align:right;">
    <div class="inv-number"><?= htmlspecialchars($inv['invoice_number']) ?></div>
    <div style="font-size:12px;color:#666;margin:6px 0;">
      Issued: <?= date('d M Y', strtotime($inv['issue_date'])) ?><br>
      Due: <?= date('d M Y', strtotime($inv['due_date'])) ?>
    </div>
    <span class="label label-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span>
  </div>
</div>

<div class="bill-to">
  <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:4px;">Bill To</div>
  <strong><?= htmlspecialchars($inv['student_name']) ?></strong><br>
  <span style="font-size:12px;color:#666;">
    Student ID: <?= htmlspecialchars($inv['student_number']) ?> &bull; Grade <?= $inv['grade'] ?>
  </span>
</div>

<table style="margin-bottom:10px;">
  <thead>
    <tr>
      <th>Description</th>
      <th class="text-right" style="width:60px;">Qty</th>
      <th class="text-right" style="width:90px;">Unit Price</th>
      <th class="text-right" style="width:90px;">Total</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($inv['items'] as $item): ?>
    <tr>
      <td><?= htmlspecialchars($item['description']) ?></td>
      <td class="text-right"><?= number_format((float)$item['quantity'],2) ?></td>
      <td class="text-right"><?= $cur ?><?= number_format((float)$item['unit_amount'],2) ?></td>
      <td class="text-right"><?= $cur ?><?= number_format((float)$item['line_total'],2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<table class="totals">
  <tr><td style="color:#666;">Subtotal</td>
      <td class="text-right"><?= $cur ?><?= number_format((float)$inv['subtotal'],2) ?></td></tr>
  <?php if ($inv['discount_total'] > 0): ?>
  <tr><td style="color:#666;">Discounts</td>
      <td class="text-right" style="color:green;">−<?= $cur ?><?= number_format((float)$inv['discount_total'],2) ?></td></tr>
  <?php endif; ?>
  <?php if ($inv['tax_total'] > 0): ?>
  <tr><td style="color:#666;"><?= htmlspecialchars($settings['tax_label']) ?></td>
      <td class="text-right"><?= $cur ?><?= number_format((float)$inv['tax_total'],2) ?></td></tr>
  <?php endif; ?>
  <tr class="total-row">
      <td>Total</td>
      <td class="text-right"><?= $cur ?><?= number_format((float)$inv['total'],2) ?></td></tr>
  <tr><td style="color:green;">Paid</td>
      <td class="text-right" style="color:green;"><?= $cur ?><?= number_format((float)$inv['amount_paid'],2) ?></td></tr>
  <tr style="font-weight:bold;">
      <td>Balance Due</td>
      <td class="text-right"><?= $cur ?><?= number_format((float)$inv['balance'],2) ?></td></tr>
</table>

<?php if (!empty($inv['payments'])): ?>
<div style="margin-top:24px;">
  <div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:8px;">
    Payments Received
  </div>
  <table>
    <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($inv['payments'] as $p): ?>
      <tr>
        <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
        <td><?= $methods[$p['method']] ?? $p['method'] ?></td>
        <td><?= htmlspecialchars($p['reference'] ?? '—') ?></td>
        <td class="text-right"><?= $cur ?><?= number_format((float)$p['amount'],2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($settings['invoice_footer'])): ?>
  <div class="footer-text"><?= nl2br(htmlspecialchars($settings['invoice_footer'])) ?></div>
<?php endif; ?>

<script>window.onload = function(){ window.print(); };</script>
</body>
</html>
