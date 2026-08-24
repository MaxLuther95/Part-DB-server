<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\AbstractMultiPlatformMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260824090000 extends AbstractMultiPlatformMigration
{
    public function getDescription(): string
    {
        return 'Allow projects to be assigned to multiple Part-DB users.';
    }

    public function mySQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_customer_project_users (customer_project_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_56B9AA6DEBA41B1E (customer_project_id), INDEX IDX_56B9AA6DA76ED395 (user_id), PRIMARY KEY(customer_project_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE production_customer_project_users ADD CONSTRAINT FK_PROD_PROJECT_USER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_customer_project_users ADD CONSTRAINT FK_PROD_PROJECT_USER_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function mySQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_customer_project_users');
    }

    public function sqLiteUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_customer_project_users (customer_project_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY(customer_project_id, user_id), CONSTRAINT FK_PROD_PROJECT_USER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_PROD_PROJECT_USER_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_56B9AA6DEBA41B1E ON production_customer_project_users (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_56B9AA6DA76ED395 ON production_customer_project_users (user_id)');
    }

    public function sqLiteDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_customer_project_users');
    }

    public function postgreSQLUp(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_customer_project_users (customer_project_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(customer_project_id, user_id))');
        $this->addSql('CREATE INDEX IDX_56B9AA6DEBA41B1E ON production_customer_project_users (customer_project_id)');
        $this->addSql('CREATE INDEX IDX_56B9AA6DA76ED395 ON production_customer_project_users (user_id)');
        $this->addSql('ALTER TABLE production_customer_project_users ADD CONSTRAINT FK_PROD_PROJECT_USER_PROJECT FOREIGN KEY (customer_project_id) REFERENCES production_customer_projects (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_customer_project_users ADD CONSTRAINT FK_PROD_PROJECT_USER_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function postgreSQLDown(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_customer_project_users');
    }
}
