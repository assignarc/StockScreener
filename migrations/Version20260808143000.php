<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for persistent_cache table in SQLite data.db
 */
final class Version20260808143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates persistent_cache table for persisting Finnhub market data and sanitized Schwab portfolio aggregates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS persistent_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            cache_key VARCHAR(255) NOT NULL,
            cache_value CLOB NOT NULL,
            expires_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
            ,
            created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
            ,
            is_sensitive BOOLEAN DEFAULT 0 NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_PERSISTENT_CACHE_KEY ON persistent_cache (cache_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cache_expires ON persistent_cache (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS persistent_cache');
    }
}
