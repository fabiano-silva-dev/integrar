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
            $app['config']->set('database.connections.mysql.host', env('DB_HOST', '127.0.0.1'));
            $app['config']->set('database.connections.mysql.port', env('DB_PORT', '3306'));
            $app['config']->set('database.connections.mysql.database', $this->resolveMysqlDatabaseName());
            $app['config']->set('database.connections.mysql.username', env('DB_USERNAME', 'root'));
            $app['config']->set('database.connections.mysql.password', env('DB_PASSWORD', ''));
        }

        return $app;
    }

    protected function usesMysqlDatabase(): bool
    {
        return str_contains(static::class, 'Tenant');
    }

    protected function resolveMysqlDatabaseName(): string
    {
        $fromCi = env('MYSQL_DATABASE');
        if (is_string($fromCi) && $fromCi !== '' && $fromCi !== ':memory:') {
            return $fromCi;
        }

        $envPath = Application::inferBasePath().'/.env';

        if (file_exists($envPath) && preg_match('/^DB_DATABASE=(.+)$/m', file_get_contents($envPath), $matches)) {
            $nome = trim($matches[1]);
            if ($nome !== '' && $nome !== ':memory:') {
                return $nome;
            }
        }

        return 'integrar';
    }
}
