<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910210100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trim approved legacy decimal padding after converting storage to exact text.';
    }

    public function up(Schema $schema): void
    {
        // A separate migration guarantees conversion has finished first. Queued SQL
        // also keeps dry-run and write-sql modes free of database mutations.
        foreach ($this->connection->fetchAllAssociative('SELECT id, decimal_value FROM production_protocol_answers WHERE decimal_value IS NOT NULL') as $row) {
            $value = (string) $row['decimal_value'];
            if (str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
            if ($value !== (string) $row['decimal_value']) {
                $this->addSql('UPDATE production_protocol_answers SET decimal_value = ? WHERE id = ?', [$value, $row['id']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore a verified backup to recover the legacy padded representation.');
    }
}
