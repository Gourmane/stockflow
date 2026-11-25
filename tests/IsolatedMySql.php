<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use PDO;
use RuntimeException;

final class IsolatedMySql
{
    public static function verify(Application $app): void
    {
        $config = $app['config'];
        $mysql = $config->get('database.connections.mysql');
        $port = filter_var(Env::get('DB_PORT'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if (! $app->environment('testing')
            || $app->configurationIsCached()
            || $app->environmentFile() !== '.env.testing'
            || $config->get('database.default') !== 'mysql'
            || $mysql['driver'] !== 'mysql'
            || ! is_string(Env::get('DB_HOST'))
            || trim(Env::get('DB_HOST')) === ''
            || $mysql['host'] !== Env::get('DB_HOST')
            || $mysql['host'] === 'localhost'
            || $port === false
            || $port === 33316
            || (int) $mysql['port'] !== $port
            || $mysql['database'] !== 'stockflow_test'
            || $mysql['username'] !== 'stockflow_test'
            || ! empty($mysql['url'])
            || ! empty($mysql['unix_socket'])
            || isset($mysql['read'])
            || isset($mysql['write'])
        ) {
            throw new RuntimeException('Feature tests require an explicit MySQL TCP host and port, stockflow_test as schema and account, and uncached testing configuration.');
        }

        $pdo = $app['db']->connection('mysql')->getPdo();
        $identity = $pdo->query('SELECT VERSION(), @@port, DATABASE(), CURRENT_USER(), CURRENT_ROLE(), @@partial_revokes')->fetch(PDO::FETCH_NUM);
        [$version, $serverPort, $database, $account, $role, $partialRevokes] = $identity;

        if (stripos($version, 'mariadb') !== false
            || (int) $serverPort !== $port
            || $database !== 'stockflow_test'
            || ! str_starts_with($account, 'stockflow_test@')
            || $role !== 'NONE'
            || ! self::hasDedicatedGrants($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN), (bool) $partialRevokes)
        ) {
            throw new RuntimeException('MySQL isolation check failed. Verify the server port, schema, authenticated account and grants.');
        }
    }

    /** @param list<string> $grants */
    public static function hasDedicatedGrants(array $grants, bool $partialRevokes): bool
    {
        $schema = $partialRevokes ? 'stockflow_test' : 'stockflow\\_test';
        $account = '`stockflow_test`@`[^`]+`';
        $scope = preg_quote('`'.$schema.'`.*', '~');
        $hasSchemaGrant = false;

        foreach ($grants as $grant) {
            if (preg_match('~^GRANT USAGE ON \*\.\* TO '.$account.'$~D', $grant)) {
                continue;
            }

            if (! preg_match('~^GRANT [A-Z ,]+ ON '.$scope.' TO '.$account.'$~D', $grant)) {
                return false;
            }

            $hasSchemaGrant = true;
        }

        return $hasSchemaGrant;
    }
}
