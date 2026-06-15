-- ============================================================
-- openSIS Billing Module — Database Schema
-- Compatible with openSIS Classic MySQL 5.7 / 8.0 / MariaDB 10.4+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Fee categories (Tuition, Lab Fee, Activity Fee, etc.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_fee_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id       INT UNSIGNED NOT NULL,
    syear           SMALLINT(4) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    frequency       ENUM('once','monthly','quarterly','annual') NOT NULL DEFAULT 'once',
    applies_to      ENUM('all','grade','student') NOT NULL DEFAULT 'all',
    grade_level     VARCHAR(10) DEFAULT NULL,   -- populated when applies_to = 'grade'
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_school_year (school_id, syear),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Fee assignments — links a fee type to a specific student
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_fee_assignments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id       INT UNSIGNED NOT NULL,
    syear           SMALLINT(4) NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    fee_type_id     INT UNSIGNED NOT NULL,
    override_amount DECIMAL(10,2) DEFAULT NULL,  -- NULL = use fee_type.amount
    due_date        DATE DEFAULT NULL,
    note            VARCHAR(255) DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,        -- staff user_id
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id, syear),
    INDEX idx_fee_type (fee_type_id),
    FOREIGN KEY (fee_type_id) REFERENCES billing_fee_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Invoices — one invoice per student per billing run
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_invoices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id       INT UNSIGNED NOT NULL,
    syear           SMALLINT(4) NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    invoice_number  VARCHAR(30) NOT NULL UNIQUE,  -- e.g. INV-2026-00042
    issue_date      DATE NOT NULL,
    due_date        DATE NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_total  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_total       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance         DECIMAL(10,2) GENERATED ALWAYS AS (total - amount_paid) STORED,
    status          ENUM('draft','sent','partial','paid','overdue','void') NOT NULL DEFAULT 'draft',
    notes           TEXT,
    created_by      INT UNSIGNED NOT NULL,
    xero_invoice_id VARCHAR(50) DEFAULT NULL,     -- populated when Xero connector added
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student_year (student_id, syear),
    INDEX idx_status (status),
    INDEX idx_school_year (school_id, syear),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Invoice line items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_invoice_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED NOT NULL,
    fee_type_id     INT UNSIGNED DEFAULT NULL,
    description     VARCHAR(255) NOT NULL,
    quantity        DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    unit_amount     DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(10,2) NOT NULL,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (fee_type_id) REFERENCES billing_fee_types(id) ON DELETE SET NULL,
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Payments recorded against invoices
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    school_id       INT UNSIGNED NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    payment_date    DATE NOT NULL,
    method          ENUM('cash','card','bank_transfer','cheque','online','other') NOT NULL DEFAULT 'cash',
    reference       VARCHAR(100) DEFAULT NULL,   -- cheque number, transaction ID, etc.
    note            VARCHAR(255) DEFAULT NULL,
    recorded_by     INT UNSIGNED NOT NULL,
    xero_payment_id VARCHAR(50) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE RESTRICT,
    INDEX idx_invoice (invoice_id),
    INDEX idx_student (student_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Sponsorships / scholarships — reduce a student's balance
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_sponsorships (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id       INT UNSIGNED NOT NULL,
    syear           SMALLINT(4) NOT NULL,
    student_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,       -- e.g. "Smith Family Scholarship"
    amount          DECIMAL(10,2) NOT NULL,
    applies_to      ENUM('all_invoices','specific_invoice') NOT NULL DEFAULT 'all_invoices',
    invoice_id      INT UNSIGNED DEFAULT NULL,
    applied_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    note            TEXT,
    created_by      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE SET NULL,
    INDEX idx_student (student_id, syear)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Module settings per school
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_settings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id           INT UNSIGNED NOT NULL UNIQUE,
    invoice_prefix      VARCHAR(10) NOT NULL DEFAULT 'INV',
    next_invoice_seq    INT UNSIGNED NOT NULL DEFAULT 1,
    default_due_days    TINYINT UNSIGNED NOT NULL DEFAULT 30,
    currency_symbol     VARCHAR(5) NOT NULL DEFAULT '$',
    currency_code       VARCHAR(3) NOT NULL DEFAULT 'USD',
    tax_rate            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tax_label           VARCHAR(30) NOT NULL DEFAULT 'Tax',
    invoice_footer      TEXT,
    email_on_generate   TINYINT(1) NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings row (school_id will be updated at setup)
INSERT IGNORE INTO billing_settings (school_id) VALUES (1);

SET FOREIGN_KEY_CHECKS = 1;
