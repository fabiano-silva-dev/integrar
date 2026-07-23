<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($this->usesMysqlDatabase()) {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql.database', $this->resolveMysqlDatabaseName());
        }

        return $app;
    }

    protected function usesMysqlDatabase(): bool
    {
        return str_contains(static::class, 'TenantIsolation')
            || str_contains(static::class, 'TenantLancamentoBulkInsert')
            || str_contains(static::class, 'TenantPlanoContas')
            || str_contains(static::class, 'TenantRegrasAmarracao')
            || str_contains(static::class, 'TenantAutomacaoFiscal');
    }

    protected function resolveMysqlDatabaseName(): string
    {
        $envPath = Application::inferBasePath().'/.env';

        if (file_exists($envPath) && preg_match('/^DB_DATABASE=(.+)$/m', file_get_contents($envPath), $matches)) {
            return trim($matches[1]);
        }

        return 'integrar';
    }
}
