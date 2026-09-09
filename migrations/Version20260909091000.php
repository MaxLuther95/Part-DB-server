<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire superseded published protocol revisions without changing existing runs or answers.';
    }

    public function up(Schema $schema): void
    {
        // Select first for portability: MySQL restricts self-referencing UPDATE subqueries.
        $ids = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT older.id
            FROM production_protocol_template_revisions older
            INNER JOIN production_protocol_template_revisions newer
                ON newer.template_id = older.template_id
                AND newer.revision_number > older.revision_number
            WHERE older.status = 'published' AND newer.status = 'published'
            SQL);

        foreach ($ids as $id) {
            $this->addSql(
                "UPDATE production_protocol_template_revisions SET status = 'retired' WHERE id = ? AND status = 'published'",
                [(int) $id]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Previously retired revisions cannot be distinguished from revisions retired by this migration.');
    }
}
