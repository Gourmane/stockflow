<?php

use Tests\IsolatedMySql;

it('accepts grants scoped to the literal test schema', function (string $schema, bool $partialRevokes) {
    expect(IsolatedMySql::hasDedicatedGrants([
        'GRANT USAGE ON *.* TO `stockflow_test`@`localhost`',
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER ON `{$schema}`.* TO `stockflow_test`@`localhost`",
    ], $partialRevokes))->toBeTrue();
})->with([
    'escaped underscore' => ['stockflow\\_test', false],
    'literal underscore with partial revokes' => ['stockflow_test', true],
]);

it('rejects grants that cannot guarantee database isolation', function (array $grants) {
    expect(IsolatedMySql::hasDedicatedGrants($grants, false))->toBeFalse();
})->with([
    'global privilege' => [['GRANT SELECT ON *.* TO `stockflow_test`@`localhost`']],
    'another schema' => [['GRANT SELECT ON `gestion_stock_db`.* TO `stockflow_test`@`localhost`']],
    'wildcard underscore' => [['GRANT ALL PRIVILEGES ON `stockflow_test`.* TO `stockflow_test`@`localhost`']],
    'role assignment' => [['GRANT `writer`@`%` TO `stockflow_test`@`localhost`']],
    'grant option' => [['GRANT ALL PRIVILEGES ON `stockflow\\_test`.* TO `stockflow_test`@`localhost` WITH GRANT OPTION']],
    'no schema grant' => [['GRANT USAGE ON *.* TO `stockflow_test`@`localhost`']],
]);
