#!/bin/bash
# =============================================================================
# Docker entrypoint for openSIS on Railway
# Writes database config from environment variables,
# runs the billing schema if not already installed,
# then starts Apache.
# =============================================================================

set -e

export APP_DIR="/var/www/html"

echo "▶ Starting openSIS entrypoint..."

# ── 1. Map Railway MySQL env vars → internal names ────────────────────────────
# Railway's MySQL plugin injects MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, etc.
# Fall back to DB_* for manual overrides.
export DB_HOST="${MYSQLHOST:-${DB_HOST:-}}"
export DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
export DB_NAME="${MYSQLDATABASE:-${DB_NAME:-railway}}"
export DB_USER="${MYSQLUSER:-${DB_USER:-}}"
export DB_PASS="${MYSQLPASSWORD:-${DB_PASS:-}}"
export APP_URL="${APP_URL:-http://localhost}"

# ── 2. Validate required environment variables ────────────────────────────────
for VAR in DB_HOST DB_NAME DB_USER DB_PASS; do
  if [ -z "${!VAR}" ]; then
    echo "ERROR: Could not resolve $VAR. Set MYSQL* vars via Railway MySQL service or DB_* manually."
    exit 1
  fi
done

# ── 3. DatabaseInc.php reads credentials via getenv() — no file write needed ──
echo "▶ Database credentials will be read from environment variables."

# ── 4. Wait for MySQL to be ready (PHP/mysqli — works with MySQL 9.x caching_sha2_password) ──
echo "▶ Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
php -r "
\$host = getenv('DB_HOST');
\$port = (int) getenv('DB_PORT');
\$user = getenv('DB_USER');
\$pass = getenv('DB_PASS');
\$name = getenv('DB_NAME');
\$max  = 60;
for (\$i = 1; \$i <= \$max; \$i++) {
    \$m = @new mysqli(\$host, \$user, \$pass, \$name, \$port);
    if (\$m->connect_errno === 0) {
        \$m->close();
        echo \"  ✓ MySQL is ready (attempt \$i)\n\";
        exit(0);
    }
    echo \"  Attempt \$i/\$max — \" . \$m->connect_error . \" — waiting 3s...\n\";
    sleep(3);
}
echo \"ERROR: Could not connect to MySQL after \$max attempts.\n\";
exit(1);
" || exit 1

# ── 5. Install openSIS core schema + billing schema if not already present ────
echo "▶ Checking database schemas..."
php "$APP_DIR/install/run_schema.php" || exit 1

# ── 6. Configure Apache to use Railway's $PORT ────────────────────────────────
APACHE_PORT="${PORT:-80}"
echo "▶ Configuring Apache on port ${APACHE_PORT}..."

cat > /etc/apache2/ports.conf <<EOF
Listen ${APACHE_PORT}
EOF

cat > /etc/apache2/sites-enabled/000-default.conf <<EOF
<VirtualHost *:${APACHE_PORT}>
    ServerName localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

echo "  ✓ Apache configured on port ${APACHE_PORT}"

# ── 7. Set correct permissions ────────────────────────────────────────────────
chown -R www-data:www-data /var/www/html/assets 2>/dev/null || true
chmod -R 775 /var/www/html/assets 2>/dev/null || true

# Ensure only mpm_prefork is active (runtime safety net)
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true

echo "▶ Starting Apache..."
exec "$@"
