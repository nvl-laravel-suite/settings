# Security Policy

Security fixes are provided for the current `1.x` release line on PHP 8.3–8.4 and Laravel 12–13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include definition, scope, type, authorization or config-override behavior, and impact. Never include secrets; this package is not a secrets manager.

Keep management routes disabled, authorize scope and key access, validate config targets, and require expected revisions for writes.

Keep settings caches on the versioned primitive-payload format and do not
allowlist Eloquent models for cache unserialization. Cache invalidation and
setting events are commit-aware so rolled-back values are not exposed.
Definitions and stored overrides must pass the strict canonical value codec;
do not bypass Actions or the repository for runtime writes.
