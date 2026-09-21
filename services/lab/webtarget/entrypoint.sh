#!/bin/sh
# Bring MariaDB, PHP-FPM and Apache up, then hold.
#
# Deliberately not a supervisor: this container exists to be driven by drill.sh
# and then thrown away, and a failure to start any of the three should stop it
# rather than be restarted around.
set -eu

echo "  starting mariadb"
mkdir -p /run/mysqld /var/log/mysql
chown -R mysql:mysql /run/mysqld /var/lib/mysql /var/log/mysql
mysqld_safe --skip-syslog >/var/log/mysql/safe.log 2>&1 &

ready=0
i=0
while [ "$i" -lt 60 ]; do
    if mysqladmin ping --silent 2>/dev/null; then
        ready=1
        break
    fi
    i=$((i + 1))
    sleep 1
done
if [ "$ready" -ne 1 ]; then
    echo "  mariadb did not start" >&2
    tail -30 /var/log/mysql/safe.log >&2 || true
    exit 1
fi

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS akconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'akconnect'@'localhost' IDENTIFIED BY 'LabPass!2026';
GRANT ALL PRIVILEGES ON akconnect.* TO 'akconnect'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "  mariadb ready"

echo "  starting php-fpm"
mkdir -p /run/php
php-fpm8.2 --daemonize

echo "  starting apache"
mkdir -p /var/run/apache2 /var/lock/apache2
# Started as a daemon rather than as PID 1, so run.sh can reload it after
# copying the tree in without the container dying with it. apachectl rather
# than apache2, because it sources /etc/apache2/envvars, which is bash.
apachectl start

echo "  ready"
exec tail -f /var/log/apache2/error.log
