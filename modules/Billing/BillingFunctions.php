<?php
if (!function_exists('_')) {
    function _($s) { return $s; }
}

/**
 * openSIS Billing Module — Core Functions
 *
 * Uses openSIS native database functions (DBQuery / DBGet / DBEscapeString)
 * and session helpers (UserSchool, UserSyear, User) so this module integrates
 * cleanly without a separate DB connection layer.
 *
 * Include this file at the top of every billing page:
 *   include_once 'modules/Billing/BillingFunctions.php';
 */

// ── Invoice number generator ─────────────────────────────────────────────────

function BillingNextInvoiceNumber(int $school_id): string
{
    $RET = DBGet(DBQuery(
        "SELECT invoice_prefix, next_invoice_seq
         FROM billing_settings
         WHERE school_id='" . (int)$school_id . "'"
    ));
    $row    = $RET[1] ?? [];
    $prefix = $row['invoice_prefix'] ?? 'INV';
    $seq    = (int)($row['next_invoice_seq'] ?? 1);
    $year   = date('Y');

    DBQuery("UPDATE billing_settings
             SET next_invoice_seq = next_invoice_seq + 1
             WHERE school_id='" . (int)$school_id . "'");

    return sprintf('%s-%s-%05d', $prefix, $year, $seq);
}

// ── Settings ─────────────────────────────────────────────────────────────────

function BillingSettings(int $school_id): array
{
    $RET = DBGet(DBQuery(
        "SELECT * FROM billing_settings WHERE school_id='" . (int)$school_id . "'"
    ));
    if (empty($RET[1])) {
        DBQuery("INSERT IGNORE INTO billing_settings (school_id) VALUES ('" . (int)$school_id . "')");
        return BillingSettings($school_id);
    }
    return $RET[1];
}

function BillingSaveSettings(int $school_id, array $data): void
{
    $prefix   = DBEscapeString(strtoupper(trim($data['invoice_prefix'] ?? 'INV')));
    $due_days = max(1, (int)($data['default_due_days'] ?? 30));
    $cur_sym  = DBEscapeString(substr(trim($data['currency_symbol'] ?? '$'), 0, 5));
    $cur_code = DBEscapeString(strtoupper(substr(trim($data['currency_code'] ?? 'USD'), 0, 3)));
    $tax_rate = min(100, max(0, (float)($data['tax_rate'] ?? 0)));
    $tax_lbl  = DBEscapeString(trim($data['tax_label'] ?? 'Tax'));
    $footer   = DBEscapeString(trim($data['invoice_footer'] ?? ''));
    $email_on = isset($data['email_on_generate']) ? 1 : 0;

    DBQuery("INSERT INTO billing_settings
             (school_id, invoice_prefix, default_due_days, currency_symbol, currency_code,
              tax_rate, tax_label, invoice_footer, email_on_generate)
             VALUES ('{$school_id}','{$prefix}','{$due_days}','{$cur_sym}','{$cur_code}',
                     '{$tax_rate}','{$tax_lbl}','{$footer}','{$email_on}')
             ON DUPLICATE KEY UPDATE
               invoice_prefix    = '{$prefix}',
               default_due_days  = '{$due_days}',
               currency_symbol   = '{$cur_sym}',
               currency_code     = '{$cur_code}',
               tax_rate          = '{$tax_rate}',
               tax_label         = '{$tax_lbl}',
               invoice_footer    = '{$footer}',
               email_on_generate = '{$email_on}'");
}

// ── Fee types ─────────────────────────────────────────────────────────────────

function BillingGetFeeTypes(int $school_id, int $syear, bool $active_only = true): array
{
    $where = "school_id='" . (int)$school_id . "' AND syear='" . (int)$syear . "'";
    if ($active_only) $where .= " AND is_active='1'";
    $RET = DBGet(DBQuery("SELECT * FROM billing_fee_types WHERE $where ORDER BY name ASC"));
    return $RET ?: [];
}

function BillingSaveFeeType(array $d, int $school_id, int $syear): void
{
    $name      = DBEscapeString(trim($d['name'] ?? ''));
    $desc      = DBEscapeString(trim($d['description'] ?? ''));
    $amount    = max(0, (float)($d['amount'] ?? 0));
    $freq      = DBEscapeString($d['frequency'] ?? 'once');
    $applies   = DBEscapeString($d['applies_to'] ?? 'all');
    $grade_lvl = DBEscapeString($d['grade_level'] ?? '');
    $active    = isset($d['is_active']) ? 1 : 1;

    if (!empty($d['id'])) {
        $id = (int)$d['id'];
        DBQuery("UPDATE billing_fee_types
                 SET name='{$name}', description='{$desc}', amount='{$amount}',
                     frequency='{$freq}', applies_to='{$applies}',
                     grade_level=" . ($grade_lvl ? "'{$grade_lvl}'" : "NULL") . ",
                     updated_at=NOW()
                 WHERE id='{$id}' AND school_id='{$school_id}'");
    } else {
        DBQuery("INSERT INTO billing_fee_types
                 (school_id, syear, name, description, amount, frequency, applies_to, grade_level)
                 VALUES ('{$school_id}','{$syear}','{$name}','{$desc}','{$amount}',
                         '{$freq}','{$applies}'," . ($grade_lvl ? "'{$grade_lvl}'" : "NULL") . ")");
    }
}

function BillingToggleFeeType(int $id, int $school_id): void
{
    DBQuery("UPDATE billing_fee_types
             SET is_active = 1 - is_active
             WHERE id='{$id}' AND school_id='{$school_id}'");
}

// ── Fee assignments ───────────────────────────────────────────────────────────

function BillingGetStudentAssignments(int $student_id, int $syear): array
{
    $RET = DBGet(DBQuery(
        "SELECT a.*, f.name AS fee_name, f.frequency, f.amount AS default_amount
         FROM billing_fee_assignments a
         JOIN billing_fee_types f ON f.id = a.fee_type_id
         WHERE a.student_id='{$student_id}' AND a.syear='{$syear}'
         ORDER BY f.name ASC"
    ));
    return $RET ?: [];
}

function BillingAssignFee(array $d, int $school_id, int $syear): void
{
    $student_id = (int)$d['student_id'];
    $fee_type   = (int)$d['fee_type_id'];
    $override   = isset($d['override_amount']) && $d['override_amount'] !== ''
                  ? "'" . (float)$d['override_amount'] . "'"
                  : 'NULL';
    $due        = !empty($d['due_date'])
                  ? "'" . DBEscapeString($d['due_date']) . "'"
                  : 'NULL';
    $note       = DBEscapeString($d['note'] ?? '');
    $created_by = (int)$_SESSION['STAFF_ID'];

    DBQuery("INSERT INTO billing_fee_assignments
             (school_id, syear, student_id, fee_type_id, override_amount, due_date, note, created_by)
             VALUES ('{$school_id}','{$syear}','{$student_id}','{$fee_type}',
                     {$override},{$due},'{$note}','{$created_by}')");
}

function BillingRemoveAssignment(int $id, int $school_id): void
{
    DBQuery("DELETE FROM billing_fee_assignments
             WHERE id='{$id}' AND school_id='{$school_id}'");
}

// ── Invoice generation ────────────────────────────────────────────────────────

/**
 * Generate an invoice for a student from their current fee assignments.
 * Returns the new invoice ID (as string from DBQuery).
 * Throws RuntimeException if no assignments exist.
 */
function BillingGenerateInvoice(int $student_id, int $school_id, int $syear): string
{
    $settings    = BillingSettings($school_id);
    $assignments = BillingGetStudentAssignments($student_id, $syear);

    if (empty($assignments)) {
        throw new RuntimeException("No fee assignments found for student #{$student_id}. Assign fees first.");
    }

    $tax_rate   = (float)$settings['tax_rate'];
    $due_days   = (int)$settings['default_due_days'];
    $issue_date = date('Y-m-d');
    $due_date   = date('Y-m-d', strtotime("+{$due_days} days"));
    $inv_number = BillingNextInvoiceNumber($school_id);
    $created_by = (int)$_SESSION['STAFF_ID'];

    $subtotal = 0.0;
    $items    = [];

    foreach ($assignments as $a) {
        $amount     = $a['override_amount'] !== null
                      ? (float)$a['override_amount']
                      : (float)$a['default_amount'];
        $line_total = round($amount, 2);
        $subtotal  += $line_total;
        $items[]    = [
            'fee_type_id'     => (int)$a['fee_type_id'],
            'description'     => $a['fee_name'],
            'quantity'        => 1.0,
            'unit_amount'     => $amount,
            'discount_amount' => 0.0,
            'line_total'      => $line_total,
        ];
    }

    // Apply sponsorships
    $sponsorships   = BillingGetStudentSponsorships($student_id, $school_id, $syear);
    $discount_total = 0.0;
    foreach ($sponsorships as $s) {
        $avail = (float)$s['amount'] - (float)$s['applied_amount'];
        $apply = min($avail, $subtotal - $discount_total);
        if ($apply > 0) {
            $discount_total += $apply;
            $items[] = [
                'fee_type_id'     => null,
                'description'     => 'Sponsorship: ' . $s['name'],
                'quantity'        => 1.0,
                'unit_amount'     => -$apply,
                'discount_amount' => 0.0,
                'line_total'      => -$apply,
            ];
        }
    }

    $taxable   = max(0.0, $subtotal - $discount_total);
    $tax_total = round($taxable * $tax_rate / 100, 2);
    $total     = round($taxable + $tax_total, 2);

    $inv_esc = DBEscapeString($inv_number);

    DBQuery("INSERT INTO billing_invoices
             (school_id, syear, student_id, invoice_number, issue_date, due_date,
              subtotal, discount_total, tax_total, total, status, created_by)
             VALUES ('{$school_id}','{$syear}','{$student_id}','{$inv_esc}',
                     '{$issue_date}','{$due_date}','{$subtotal}','{$discount_total}',
                     '{$tax_total}','{$total}','draft','{$created_by}')");

    $invoice_id = DBLastInsertId();

    foreach ($items as $i => $item) {
        $desc   = DBEscapeString($item['description']);
        $ft_id  = $item['fee_type_id'] !== null ? "'{$item['fee_type_id']}'" : 'NULL';
        DBQuery("INSERT INTO billing_invoice_items
                 (invoice_id, fee_type_id, description, quantity, unit_amount,
                  discount_amount, line_total, sort_order)
                 VALUES ('{$invoice_id}',{$ft_id},'{$desc}',
                         '{$item['quantity']}','{$item['unit_amount']}',
                         '{$item['discount_amount']}','{$item['line_total']}','{$i}')");
    }

    return $invoice_id;
}

// ── Invoice retrieval ─────────────────────────────────────────────────────────

function BillingGetInvoice(int $invoice_id, int $school_id): array
{
    $RET = DBGet(DBQuery(
        "SELECT i.*,
                CONCAT(s.FIRST_NAME,' ',s.LAST_NAME) AS student_name,
                s.STUDENT_ID AS student_number,
                '' AS grade
         FROM billing_invoices i
         JOIN students s ON s.STUDENT_ID = i.student_id
         WHERE i.id='{$invoice_id}' AND i.school_id='{$school_id}'"
    ));
    if (empty($RET[1])) return [];

    $inv = $RET[1];

    $items_RET = DBGet(DBQuery(
        "SELECT * FROM billing_invoice_items
         WHERE invoice_id='{$invoice_id}' ORDER BY sort_order ASC"
    ));
    $inv['items'] = $items_RET ?: [];

    $pay_RET = DBGet(DBQuery(
        "SELECT p.*, CONCAT(st.FIRST_NAME,' ',st.LAST_NAME) AS recorded_by_name
         FROM billing_payments p
         JOIN staff st ON st.STAFF_ID = p.recorded_by
         WHERE p.invoice_id='{$invoice_id}' ORDER BY p.payment_date ASC"
    ));
    $inv['payments'] = $pay_RET ?: [];

    return $inv;
}

function BillingGetInvoices(int $school_id, int $syear, array $filters = []): array
{
    $where = "i.school_id='{$school_id}' AND i.syear='{$syear}'";

    if (!empty($filters['status'])) {
        $s = DBEscapeString($filters['status']);
        $where .= " AND i.status='{$s}'";
    }
    if (!empty($filters['student_id'])) {
        $sid = (int)$filters['student_id'];
        $where .= " AND i.student_id='{$sid}'";
    }
    if (!empty($filters['overdue'])) {
        $where .= " AND i.due_date < CURDATE() AND i.status NOT IN ('paid','void')";
    }

    $RET = DBGet(DBQuery(
        "SELECT i.*, CONCAT(s.FIRST_NAME,' ',s.LAST_NAME) AS student_name, '' AS grade
         FROM billing_invoices i
         JOIN students s ON s.STUDENT_ID = i.student_id
         WHERE {$where}
         ORDER BY i.issue_date DESC"
    ));
    return $RET ?: [];
}

// ── Payments ──────────────────────────────────────────────────────────────────

function BillingRecordPayment(array $d, int $school_id): string
{
    $inv_id     = (int)$d['invoice_id'];
    $student_id = (int)$d['student_id'];
    $amount     = (float)$d['amount'];
    $date       = DBEscapeString($d['payment_date'] ?: date('Y-m-d'));
    $method     = DBEscapeString($d['method'] ?? 'cash');
    $ref        = DBEscapeString($d['reference'] ?? '');
    $note       = DBEscapeString($d['note'] ?? '');
    $by         = (int)$_SESSION['STAFF_ID'];

    DBQuery("INSERT INTO billing_payments
             (invoice_id, student_id, school_id, amount, payment_date, method, reference, note, recorded_by)
             VALUES ('{$inv_id}','{$student_id}','{$school_id}','{$amount}',
                     '{$date}','{$method}','{$ref}','{$note}','{$by}')");

    $payment_id = DBLastInsertId();

    // Recalculate amount_paid and status on the invoice
    DBQuery("UPDATE billing_invoices
             SET amount_paid = (
                 SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE invoice_id='{$inv_id}'
             ),
             status = CASE
                 WHEN (SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE invoice_id='{$inv_id}') >= total
                      THEN 'paid'
                 WHEN (SELECT COALESCE(SUM(amount),0) FROM billing_payments WHERE invoice_id='{$inv_id}') > 0
                      THEN 'partial'
                 ELSE status
             END
             WHERE id='{$inv_id}'");

    return $payment_id;
}

// ── Sponsorships ──────────────────────────────────────────────────────────────

function BillingGetStudentSponsorships(int $student_id, int $school_id, int $syear): array
{
    $RET = DBGet(DBQuery(
        "SELECT * FROM billing_sponsorships
         WHERE student_id='{$student_id}' AND school_id='{$school_id}' AND syear='{$syear}'
         ORDER BY created_at ASC"
    ));
    return $RET ?: [];
}

function BillingSaveSponsorship(array $d, int $school_id, int $syear): void
{
    $student_id = (int)$d['student_id'];
    $name       = DBEscapeString($d['name'] ?? '');
    $amount     = (float)($d['amount'] ?? 0);
    $applies_to = DBEscapeString($d['applies_to'] ?? 'all_invoices');
    $inv_id     = !empty($d['invoice_id']) ? "'" . (int)$d['invoice_id'] . "'" : 'NULL';
    $note       = DBEscapeString($d['note'] ?? '');
    $by         = (int)$_SESSION['STAFF_ID'];

    DBQuery("INSERT INTO billing_sponsorships
             (school_id, syear, student_id, name, amount, applies_to, invoice_id, note, created_by)
             VALUES ('{$school_id}','{$syear}','{$student_id}','{$name}','{$amount}',
                     '{$applies_to}',{$inv_id},'{$note}','{$by}')");
}

// ── Dashboard summary ─────────────────────────────────────────────────────────

function BillingDashboardSummary(int $school_id, int $syear): array
{
    $totals_RET = DBGet(DBQuery(
        "SELECT
            COUNT(*)                                              AS invoice_count,
            COALESCE(SUM(total),0)                               AS total_billed,
            COALESCE(SUM(amount_paid),0)                         AS total_collected,
            COALESCE(SUM(CASE WHEN status='overdue' THEN balance END),0) AS overdue_amount,
            COALESCE(SUM(CASE WHEN status='draft'   THEN total  END),0) AS draft_amount
         FROM billing_invoices
         WHERE school_id='{$school_id}' AND syear='{$syear}' AND status != 'void'"
    ));

    $monthly_RET = DBGet(DBQuery(
        "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS month,
                COALESCE(SUM(amount),0) AS collected
         FROM billing_payments
         WHERE school_id='{$school_id}' AND YEAR(payment_date)='{$syear}'
         GROUP BY month ORDER BY month ASC"
    ));

    return [
        'totals'  => $totals_RET[1]  ?? [],
        'monthly' => $monthly_RET    ?: [],
    ];
}

// ── DBLastInsertId helper ─────────────────────────────────────────────────────
// openSIS doesn't expose lastInsertId natively, so we query it.

function DBLastInsertId(): string
{
    $RET = DBGet(DBQuery("SELECT LAST_INSERT_ID() AS id"));
    return (string)($RET[1]['id'] ?? '0');
}
