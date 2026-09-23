# Apache 2.4 + PHP-FPM + MariaDB, which is what production is.
#
# The lab ran everything on PHP's built-in server. That server reads no
# .htaccess, has no mod_php, and passes every header straight through — so it
# could not have found any of the three faults a real aaPanel deployment found
# in one evening: a 500 on every page from php_flag under FPM, a webroot that
# served deploy/ and .git, and an Authorization header that Apache drops before
# PHP ever sees it.
#
# Debian rather than aaPanel's own stack, because aaPanel is a control panel
# with no redistributable image. What matters is the combination — Apache
# talking to PHP-FPM over mod_proxy_fcgi — and that is identical.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-fcgid \
        php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-curl php8.2-zip \
        php8.2-xml php8.2-gd php8.2-bcmath php8.2-intl \
        mariadb-server \
        curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# proxy_fcgi is how Apache reaches PHP-FPM; rewrite and headers are what the
# application's .htaccess needs; setenvif carries the Authorization fallback.
RUN a2enmod proxy proxy_fcgi rewrite headers setenvif \
    && a2dismod mpm_event && a2enmod mpm_prefork

# The document root must exist before Apache starts, or it refuses to. run.sh
# fills it afterwards.
RUN mkdir -p /var/www/app && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY site.conf /etc/apache2/sites-available/000-default.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
