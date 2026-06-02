<?php

namespace Bschmitt\Amqp\Support;

/**
 * Laravel Cloud compatibility helpers.
 *
 * Laravel Cloud exposes a handful of environment variables and conventions
 * that hosted workloads can lean on (deployment id, region, optional
 * `AMQP_URL` DSN). This class is a small detector + DSN parser used by the
 * service provider during `register()` to opportunistically hydrate the
 * configured AMQP connection without forcing a hard dependency on the Cloud
 * platform itself.
 *
 * Detection is intentionally loose — anything with `LARAVEL_CLOUD=1`,
 * `LARAVEL_CLOUD_DEPLOYMENT_ID=...`, or `FORGE_REGION=...` reads as "managed
 * environment" so the same logic is useful on Forge, Vapor, Render, Fly.io,
 * etc.
 */
class LaravelCloud
{
    /**
     * Hosting marker. True when the runtime looks like a managed environment.
     */
    public static function isHosted(): bool
    {
        return self::hasNonEmptyEnv('LARAVEL_CLOUD')
            || self::hasNonEmptyEnv('LARAVEL_CLOUD_DEPLOYMENT_ID')
            || self::hasNonEmptyEnv('CLOUD_DEPLOYMENT_ID')
            || self::hasNonEmptyEnv('FLY_APP_NAME')
            || self::hasNonEmptyEnv('RENDER_SERVICE_ID')
            || self::hasNonEmptyEnv('VAPOR_SSM_PATH')
            || self::hasNonEmptyEnv('FORGE_SITE_NAME');
    }

    /**
     * Free-form region tag the platform exposes (best-effort).
     */
    public static function region(): ?string
    {
        foreach (['LARAVEL_CLOUD_REGION', 'FLY_REGION', 'AWS_REGION', 'CLOUD_REGION', 'FORGE_REGION'] as $key) {
            $value = self::env($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    public static function deploymentId(): ?string
    {
        foreach (['LARAVEL_CLOUD_DEPLOYMENT_ID', 'CLOUD_DEPLOYMENT_ID', 'FLY_ALLOC_ID', 'RENDER_INSTANCE_ID'] as $key) {
            $value = self::env($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Resolve an AMQP DSN from `AMQP_URL` / `CLOUDAMQP_URL` / `RABBITMQ_URL`.
     */
    public static function dsn(): ?string
    {
        foreach (['AMQP_URL', 'CLOUDAMQP_URL', 'RABBITMQ_URL'] as $key) {
            $value = self::env($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Decompose an `amqp(s)://user:pass@host:port/vhost` DSN into properties
     * compatible with the package's `config/amqp.php` `properties.*` block.
     *
     * Returns an empty array on parse failure.
     *
     * @return array<string, mixed>
     */
    public static function parseDsn(string $dsn): array
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return [];
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['amqp', 'amqps'], true)) {
            return [];
        }

        $rawPath = isset($parts['path']) ? (string) $parts['path'] : '';
        $vhost = self::decodeVhost($rawPath);

        $properties = [
            'host' => isset($parts['host']) ? (string) $parts['host'] : 'localhost',
            'port' => isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'amqps' ? 5671 : 5672),
            'vhost' => $vhost,
        ];
        if (isset($parts['user'])) {
            $properties['username'] = rawurldecode((string) $parts['user']);
        }
        if (isset($parts['pass'])) {
            $properties['password'] = rawurldecode((string) $parts['pass']);
        }
        if ($scheme === 'amqps') {
            $properties['ssl_options'] = [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ];
        }

        return $properties;
    }

    /**
     * Merge cloud-derived properties on top of an existing connection block,
     * favouring explicit user config when it's not the package's default
     * placeholder.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $cloud
     * @return array<string, mixed>
     */
    public static function mergeProperties(array $existing, array $cloud): array
    {
        $merged = $existing;
        foreach ($cloud as $key => $value) {
            $current = $existing[$key] ?? null;
            if ($current === null || $current === '' || ($key === 'host' && $current === 'localhost')) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        return [
            'hosted' => self::isHosted(),
            'region' => self::region(),
            'deployment_id' => self::deploymentId(),
            'has_dsn' => self::dsn() !== null,
        ];
    }

    /**
     * Decode the vhost portion of a RabbitMQ AMQP DSN path.
     *
     * The convention is:
     *   - `/`              -> default vhost `/`
     *   - `/prod`          -> vhost `prod` (path delimiter only)
     *   - `/%2Fmain`       -> vhost `/main` (leading slash is part of name)
     *   - empty path       -> default `/`
     */
    protected static function decodeVhost(string $rawPath): string
    {
        if ($rawPath === '' || $rawPath === '/') {
            return '/';
        }
        // Strip the URL path delimiter, then decode any `%2F` that's part of
        // the actual vhost name.
        $trimmed = ltrim($rawPath, '/');
        $decoded = rawurldecode($trimmed);
        if ($decoded === '') {
            return '/';
        }
        return $decoded;
    }

    protected static function hasNonEmptyEnv(string $key): bool
    {
        $value = self::env($key);
        return $value !== null && $value !== '';
    }

    protected static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }
        if ($value === null || $value === false) {
            return null;
        }
        return (string) $value;
    }
}
