<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_config table for user-editable runtime configuration persisted in data.db';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            config_key VARCHAR(255) NOT NULL,
            config_value CLOB DEFAULT NULL,
            updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_APP_CONFIG_KEY ON app_config (config_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_config');
    }
}
