# Sécurité & Recommandations d'Infrastructure — GaragePro

## 1. Secrets et variables d'environnement
- Aucune clé secrète (`SASPAY_SECRET_KEY`, `SASPAY_WEBHOOK_SECRET`, `MAIL_PASSWORD`, `ADMIN_PASSWORD`) ne doit être versionnée dans Git.
- Utiliser `sk_test_...` en développement et `sk_live_...` uniquement dans le `.env` de production.
- `APP_DEBUG=false` obligatoire en production.

## 2. Protection DDoS, Proxy & Réseau
- Placer un proxy/CDN (ex. **Cloudflare**) devant le serveur applicatif pour absorber les attaques volumétriques et activer Turnstile/hCaptcha sur `/inscription` et `/connexion`.
- Configurer `TRUSTED_PROXIES` dans `.env` avec les plages IP du reverse proxy afin que `X-Forwarded-For` soit correctement interprété par `BlockSuspiciousIp` et `RateLimiter`.
- Activer **fail2ban** sur SSH, Nginx/Apache et les journaux d'authentification.
- Limiter la taille des requêtes HTTP (ex. `client_max_body_size 10M;` sous Nginx) et fixer des timeouts stricts (`fastcgi_read_timeout 30s;`).
- Ne jamais exposer PostgreSQL, MySQL ou Redis sur Internet (liaison `127.0.0.1` ou réseau privé uniquement).

## 3. Contrôles applicatifs intégrés
- Middleware `BlockSuspiciousIp` en tête de pile (blocage auto des scans `/.env`, `/wp-admin`, `/phpmyadmin`, motifs SQLi/XSS, gestion liste blanche/noire depuis `/admin`).
- En-têtes de sécurité (`CSP`, `HSTS`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`).
- Cookies de session `Secure`, `HttpOnly`, `SameSite=lax` et chiffrés.
- Stockage des photos/signatures hors webroot (`storage/app/private`), vérification MIME réelle (`finfo`), accès uniquement par URL signée temporaire.
- Audit continu dans la CI via `composer audit` et `npm audit --audit-level=high`.
