<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

/** Normalizes installations on which Version20260821012000 was applied before the ORM index names were aligned. */
final class Version20260821012100 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Normalize direct system-template slot indexes.';
    }

    public function mySQLUp(Schema $schema): void
    {
        if (!$this->hasLegacyIndexes($schema)) {
            return;
        }

        $this->addSql('ALTER TABLE production_system_template_slot_templates RENAME INDEX IDX_PROD_SLOT_TEMPLATE_SLOT TO IDX_F56E0B7659E5119C, RENAME INDEX IDX_PROD_SLOT_TEMPLATE_ALLOWED TO IDX_F56E0B76B7D948A0');
    }

    public function mySQLDown(Schema $schema): void
    {
    }

    public function sqLiteUp(Schema $schema): void
    {
        if (!$this->hasLegacyIndexes($schema)) {
            return;
        }

        $this->addSql('DROP INDEX IDX_PROD_SLOT_TEMPLATE_SLOT');
        $this->addSql('DROP INDEX IDX_PROD_SLOT_TEMPLATE_ALLOWED');
        $this->addSql('CREATE INDEX IDX_F56E0B7659E5119C ON production_system_template_slot_templates (slot_id)');
        $this->addSql('CREATE INDEX IDX_F56E0B76B7D948A0 ON production_system_template_slot_templates (allowed_template_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
    }

    public function postgreSQLUp(Schema $schema): void
    {
        if (!$this->hasLegacyIndexes($schema)) {
            return;
        }

        $this->addSql('ALTER INDEX IDX_PROD_SLOT_TEMPLATE_SLOT RENAME TO IDX_F56E0B7659E5119C');
        $this->addSql('ALTER INDEX IDX_PROD_SLOT_TEMPLATE_ALLOWED RENAME TO IDX_F56E0B76B7D948A0');
    }

    public function postgreSQLDown(Schema $schema): void
    {
    }

    private function hasLegacyIndexes(Schema $schema): bool
    {
        return $schema->hasTable('production_system_template_slot_templates')
            && $schema->getTable('production_system_template_slot_templates')->hasIndex('IDX_PROD_SLOT_TEMPLATE_SLOT');
    }
}
