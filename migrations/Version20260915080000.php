<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate customer references from order notes and retain existing imported references.';
    }

    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_customer_projects ADD customer_reference VARCHAR(255) DEFAULT NULL');
        foreach ($this->connection->fetchAllAssociative("SELECT id, notes FROM production_customer_projects WHERE notes LIKE 'Kundenreferenz: %'") as $row) {
            if (1 === preg_match('/\AKundenreferenz: ([^\r\n]{1,255})(?:\r?\n(.*))?\z/su', $row['notes'], $match)) {
                $reference = trim($match[1]);
                $notes = trim($match[2] ?? '');
                $this->addSql('UPDATE production_customer_projects SET customer_reference = ?, notes = ? WHERE id = ?', [
                    '' === $reference ? null : $reference,
                    '' === $notes ? null : $notes,
                    $row['id'],
                ]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, customer_reference, notes FROM production_customer_projects WHERE customer_reference IS NOT NULL') as $row) {
            $notes = 'Kundenreferenz: '.$row['customer_reference'];
            if (null !== $row['notes'] && '' !== $row['notes']) {
                $notes .= "\n".$row['notes'];
            }
            $this->addSql('UPDATE production_customer_projects SET notes = ? WHERE id = ?', [$notes, $row['id']]);
        }
        $this->addSql('ALTER TABLE production_customer_projects DROP COLUMN customer_reference');
    }
}
