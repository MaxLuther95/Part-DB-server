<?php

declare(strict_types=1);

// Deliberately restricted to the disposable CI upgrade database. Never use a company dump.
require getcwd().'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(getcwd().'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$connection = $em->getConnection();
if (getenv('CI_UPGRADE_TEST') !== '1' || $connection->getDatabase() !== 'partdb_upgrade_test') {
    throw new RuntimeException('Only the disposable partdb_upgrade_test database is allowed.');
}
$state = getenv('UPGRADE_STATE');
if (!$state) {
    throw new RuntimeException('An explicit synthetic fixture state path is required.');
}
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $category = (new App\Entity\Parts\Category())->setName('Synthetic upgrade category');
    $location = (new App\Entity\Parts\StorageLocation())->setName('Synthetic upgrade location');
    $part = (new App\Entity\Parts\Part())->setName('Synthetic upgrade part')->setCategory($category);
    $lot = (new App\Entity\Parts\PartLot())->setAmount(7)->setStorageLocation($location);
    $part->addPartLot($lot);
    foreach ([$category, $location, $part] as $entity) {
        $em->persist($entity);
    }
    $em->flush();
    $snapshot = [];
    foreach (['parts', 'categories', 'storelocations', 'part_lots'] as $table) {
        $snapshot[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
    }
    file_put_contents($state, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
} elseif ($mode === 'interrupt') {
    // Reproduce a committed first DDL statement with no migration-history entry.
    $connection->executeStatement('CREATE INDEX IDX_PROD_ORDER_STATUS_DATE ON production_customer_projects (status, order_date)');
} elseif ($mode === 'verify') {
    $snapshot = json_decode(file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    foreach ($snapshot as $table => $rows) {
        if (!in_array($table, ['parts', 'categories', 'storelocations', 'part_lots'], true) || $rows === []) {
            throw new RuntimeException('Unexpected synthetic fixture state.');
        }
        // New columns may be added during an upgrade; existing values must survive.
        $columns = array_map($connection->quoteIdentifier(...), array_keys($rows[0]));
        $actual = $connection->fetchAllAssociative('SELECT '.implode(', ', $columns).' FROM '.$table.' ORDER BY id');
        if ($rows !== $actual) {
            throw new RuntimeException('The upgrade changed existing synthetic inventory.');
        }
    }
    foreach ([
        ['production_customer_projects', 'idx_prod_order_status_date', ['status', 'order_date']],
        ['production_customer_projects', 'idx_prod_order_customer_date', ['customer_id', 'order_date']],
        ['production_projects', 'idx_prod_project_status_date', ['status', 'datetime_added']],
        ['production_build_instances', 'idx_prod_build_status_date', ['status', 'datetime_added']],
        ['production_build_instances', 'idx_prod_build_order_status', ['customer_project_id', 'status']],
    ] as [$table, $name, $columns]) {
        $index = $connection->createSchemaManager()->introspectTable($table)->getIndex($name);
        if ($index->getColumns() !== $columns || !$index->isSimpleIndex()) {
            throw new RuntimeException('An expected production index is missing or incorrect.');
        }
    }
} else {
    throw new RuntimeException('Unknown upgrade test mode.');
}
echo 'Synthetic upgrade check: '.$mode." passed\n";
