<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProductionReadinessCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Validate production configuration without printing secrets or changing state';

    public function handle(): int
    {
        $failures = collect();

        if (config('app.env') !== 'production') {
            $failures->push('APP_ENV must be production.');
        }

        if ((bool) config('app.debug')) {
            $failures->push('APP_DEBUG must be false.');
        }

        if (! $this->isPublicHttpsUrl(config('app.url'))) {
            $failures->push('APP_URL must be a public HTTPS URL.');
        }

        $publicWebUrl = config('tickets.public_web_url');

        if (! $this->isPublicHttpsUrl($publicWebUrl)) {
            $failures->push('PUBLIC_WEB_URL must be a public HTTPS URL.');
        }

        if (! is_string(config('app.key')) || trim((string) config('app.key')) === '') {
            $failures->push('APP_KEY must be configured.');
        }

        if (config('database.default') === 'sqlite') {
            $failures->push('SQLite is not accepted for the production deployment.');
        }

        $origins = collect(config('cors.allowed_origins', []))
            ->filter(fn ($origin) => is_string($origin) && trim($origin) !== '');

        if ($origins->isEmpty() || $origins->contains(fn ($origin) => ! $this->isPublicHttpsOrigin($origin))) {
            $failures->push('CORS_ALLOWED_ORIGINS must contain only explicit HTTPS origins.');
        }

        $publicWebOrigin = $this->normalizeOrigin($publicWebUrl);
        $normalizedOrigins = $origins->map(fn ($origin) => $this->normalizeOrigin($origin));

        if ($publicWebOrigin !== null && ! $normalizedOrigins->contains($publicWebOrigin)) {
            $failures->push('CORS_ALLOWED_ORIGINS must include the PUBLIC_WEB_URL origin.');
        }

        if (strtolower((string) config('logging.channels.single.level')) === 'debug') {
            $failures->push('LOG_LEVEL must not be debug in production.');
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $failures->push('Laravel runtime directories must exist and be writable.');
                break;
            }
        }

        if ($failures->isNotEmpty()) {
            $this->error('Production readiness check failed:');
            $failures->each(fn (string $failure) => $this->line(" - {$failure}"));

            return self::FAILURE;
        }

        $this->info('Production configuration passed the readiness check.');

        return self::SUCCESS;
    }

    private function isPublicHttpsUrl(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $url = parse_url(trim($value));

        return is_array($url)
            && strtolower((string) ($url['scheme'] ?? '')) === 'https'
            && $this->isPublicHost((string) ($url['host'] ?? ''));
    }

    private function isPublicHttpsOrigin(mixed $value): bool
    {
        if (! $this->isPublicHttpsUrl($value)) {
            return false;
        }

        $url = parse_url(trim((string) $value));

        return is_array($url)
            && ! isset($url['user'])
            && ! isset($url['pass'])
            && ! isset($url['query'])
            && ! isset($url['fragment'])
            && in_array($url['path'] ?? '', ['', '/'], true);
    }

    private function normalizeOrigin(mixed $value): ?string
    {
        if (! $this->isPublicHttpsOrigin($value)) {
            return null;
        }

        $url = parse_url(trim((string) $value));
        $port = isset($url['port']) ? ':'.$url['port'] : '';

        return 'https://'.strtolower((string) $url['host']).$port;
    }

    private function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return str_contains($host, '.');
    }
}
