# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/settings/security/advisories/new).

Security fixes are provided for the published `5.x` release line. Composer declares PHP `^8.4` and Laravel `^12.0|^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with MySQL 8.4 and PostgreSQL 17 persistence contracts; PHP 8.5, Laravel 12 and MariaDB require separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include definition, scope, type, authorization or config-override behavior, and impact. Never include secrets; this package is not a secrets manager.

Keep management routes disabled, authorize scope and key access, validate config targets, and require expected revisions for writes.

Keep settings caches on the versioned primitive-payload format and do not
allowlist Eloquent models for cache unserialization. Cache invalidation and
setting events are commit-aware so rolled-back values are not exposed.
Definitions and stored overrides must pass the strict canonical value codec;
do not bypass Actions or the repository for runtime writes.
