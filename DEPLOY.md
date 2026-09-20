# Deployment

Three things to get right: point the web server at the repository root with
everything except the public entry points denied, give PHP the extensions it
needs, and set up the cron line. Everything else the installer handles.

---

## Apache

`mod_rewrite` must be enabled **and** `.htaccess` must be honoured. The
shipped `.htaccess` does the rest.

```apache
<VirtualHost *:443>
    ServerName net.example.com
    DocumentRoot /var/www/net.example.com

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/net.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/net.example.com/privkey.pem

    <Directory /var/www/net.example.com>
        # Without this the shipped .htaccess is ignored and the application
        # directories become readable over the web.
        AllowOverride All
        Require all granted
        Options -Indexes -MultiViews
    </Directory>

    # Long-running steps: a database dump or a large file copy.
    <IfModule mod_fcgid.c>
        FcgidIOTimeout 900
    </IfModule>

    ErrorLog  ${APACHE_LOG_DIR}/net.example.com-error.log
    CustomLog ${APACHE_LOG_DIR}/net.example.com-access.log combined
</VirtualHost>

<VirtualHost *:80>
    ServerName net.example.com
    Redirect permanent / https://net.example.com/
</VirtualHost>
```

```bash
a2enmod rewrite ssl headers
systemctl reload apache2
```

Confirm rewriting actually works before going further — the installer's
requirements step probes it with a real request, which is the check that
matters.

---

## nginx

nginx does not read `.htaccess` **at all**, so the denials have to be in the
server block. Getting this wrong exposes `config/config.php`.

```nginx
server {
    listen 443 ssl http2;
    server_name net.example.com;
    root /var/www/net.example.com;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/net.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/net.example.com/privkey.pem;

    client_max_body_size 20m;

    # Everything that is not a real file goes to the front controller.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # The installer's mod_rewrite probe.
    location = /__rewrite_probe {
        try_files /install/probe.php =404;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/install/probe.php;
    }

    # Application directories — nginx has no .htaccess to fall back on.
    location ~ ^/(app|config|database|storage|cli|services|tests)/ {
        deny all;
        return 403;
    }

    # Dotfiles, the manifest, the version marker, SQL and logs.
    location ~ /\.(?!well-known) { deny all; }
    location ~ \.(sql|log)$       { deny all; }
    location = /update.json       { deny all; }
    location = /VERSION           { deny all; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # A backup or a file copy can outlast the default 60s.
        fastcgi_read_timeout 900;
    }

    location ~* \.(css|js|svg|png|jpg|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}

server {
    listen 80;
    server_name net.example.com;
    return 301 https://$host$request_uri;
}
```

Verify the denials actually bite:

```bash
curl -o /dev/null -w '%{http_code}\n' https://net.example.com/config/config.php   # 403
curl -o /dev/null -w '%{http_code}\n' https://net.example.com/app/Core/DB.php     # 403
curl -o /dev/null -w '%{http_code}\n' https://net.example.com/health.php          # 200
```

---

## aaPanel

1. **Website → Add site**, domain `net.example.com`, PHP 8.2, no database
   (the installer creates one, or create it yourself).
2. **Database → Add database**; note the name, user and password.
3. Upload the repository to the site root (or `git clone` into it).
4. **Website → Settings → Site directory**: leave *Running directory* at `/`.
   The front controller is at the root.
5. **Website → Settings → PHP version → Install extensions**: confirm
   `pdo_mysql openssl curl zip mbstring fileinfo`, and add `sodium` and
   `opcache`. `fileinfo` and `sodium` are often off by default.
6. **Website → Settings → Configuration**, raise the limits the updater needs:

   ```ini
   max_execution_time = 300
   memory_limit = 256M
   upload_max_filesize = 20M
   post_max_size = 20M
   ```

7. Fix ownership so PHP can write:

   ```bash
   chown -R www:www /www/wwwroot/net.example.com
   chmod -R 775 /www/wwwroot/net.example.com/storage \
                /www/wwwroot/net.example.com/uploads \
                /www/wwwroot/net.example.com/config
   ```

8. **Website → SSL → Let's Encrypt**, then turn on *Force HTTPS*.
9. Open `https://net.example.com/install`.
10. **Cron → Add task**, type *Shell Script*, every 5 minutes:

    ```bash
    /www/server/php/82/bin/php /www/wwwroot/net.example.com/cli/worker.php
    ```

11. Delete the installer: `rm -rf /www/wwwroot/net.example.com/install`.

> aaPanel's nginx template does not read `.htaccess`. If the site runs nginx
> rather than Apache, paste the `location` blocks from the nginx section into
> **Website → Settings → Configuration** or the install is exposed.

---

## PHP configuration

```ini
; Long enough for a database dump or a large file copy. The updater works in
; resumable steps, so a lower value is survivable — it just causes retries.
max_execution_time = 300
memory_limit = 256M

; Uploads: agent binaries and restored backups.
upload_max_filesize = 20M
post_max_size = 20M

; Never in production.
display_errors = Off
log_errors = On

; Recommended. The updater calls opcache_reset() after applying files; without
; it, old bytecode keeps serving and the update appears not to have happened.
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
```

---

## Permissions

```bash
# Owned by the web server user, readable by nobody else.
chown -R www-data:www-data /var/www/net.example.com
find /var/www/net.example.com -type d -exec chmod 755 {} \;
find /var/www/net.example.com -type f -exec chmod 644 {} \;

# Writable at runtime.
chmod -R 775 storage uploads config

# Credentials.
chmod 640 config/config.php config/.env
```

After installing, `config/` no longer needs to be writable. Tightening it to
`755` is a reasonable hardening step — but the updater needs it writable again
only if you ever regenerate the config, which it does not do.

---

## Scheduler

```cron
*/5 * * * * /usr/bin/php /var/www/net.example.com/cli/worker.php >> /var/www/net.example.com/storage/logs/cron.log 2>&1
```

One line covers update checks, the offline-device sweep, scheduled backups,
retention pruning, queued jobs and alerts. Each task decides for itself
whether it is due, so there is nothing else to schedule.

Confirm it works:

```bash
sudo -u www-data php /var/www/net.example.com/cli/worker.php --verbose
```

---

## The Go services (Phase 2)

Not built yet — see [PROGRESS.md](PROGRESS.md). When they land, they deploy
separately from the panel; the panel does not need them to run.

The intended shape:

```yaml
# services/docker-compose.yml
services:
  coordinator:
    build: ./coordinator
    restart: unless-stopped
    ports:
      - "8443:8443/udp"     # STUN-like probe and rendezvous
      - "8443:8443/tcp"     # control channel
    environment:
      PANEL_URL:      https://net.example.com
      PANEL_SECRET:   ${COORDINATOR_SHARED_SECRET}   # config coordinator.shared_secret
      REDIS_URL:      redis://redis:6379
    depends_on: [redis]

  relay:
    build: ./relay
    restart: unless-stopped
    ports:
      - "51820:51820/udp"   # WireGuard
      - "443:443/tcp"       # fallback where UDP is blocked
    environment:
      RELAY_NAME:   in-bom-1
      RELAY_REGION: in
      PANEL_URL:    https://net.example.com
      PANEL_SECRET: ${COORDINATOR_SHARED_SECRET}

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes"]
    volumes: [redis-data:/data]

volumes:
  redis-data:
```

A relay prints its public key on first start; register it under
**Relays → Register a relay**. Relays never see plaintext, so they can sit on
cheap hosts in whatever regions you need.

Firewall: UDP 51820 and TCP 443 to each relay, UDP and TCP 8443 to the
coordinator. Agents need outbound UDP; the TCP fallback exists for networks
that block it.

---

## Scaling out

The web tier is stateless — sessions are in MySQL — so it scales by adding
nodes behind a load balancer.

* Set `app.trusted_proxies` to the balancer's addresses, or `Request::ip()`
  will see the balancer for every client and the rate limiter will treat the
  whole internet as one caller.
* Share `storage/backups` (NFS or S3-compatible), or take backups on one
  designated node only.
* Turn on Redis (`redis.enabled`) so rate limiting and caching are shared.
* Point `db.read_host` at a replica; the data layer already splits
  `DB::read()` from `DB::write()`.
* Run the cron worker on **one** node. It takes a lock, so a second copy is
  harmless, but there is no reason to pay for it.

---

## Upgrading an existing install

Use the panel: **System → Updates → Update now**, or `php cli/update.php
--apply`. A backup is taken and verified first, and a failure rolls back
automatically.

To upgrade by hand — restoring from a backup, or recovering a failed
rollback:

```bash
cd /var/www/net.example.com
php cli/backup.php                      # take one first
tar -xzf storage/backups/<timestamp>/files.tar.gz
php cli/migrate.php
rm -f storage/maintenance.flag
```

`config/config.php`, `config/.env` and `uploads/` are never touched by an
update and do not need restoring.
