<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create stock and watchlist tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            name VARCHAR(255) NOT NULL,
            sector VARCHAR(100) NOT NULL,
            price DOUBLE PRECISION DEFAULT NULL,
            target_price DOUBLE PRECISION DEFAULT NULL,
            market_cap VARCHAR(50) DEFAULT NULL,
            rev_growth VARCHAR(50) DEFAULT NULL,
            gross_margin VARCHAR(50) DEFAULT NULL,
            cash_runway VARCHAR(50) DEFAULT NULL,
            short_interest VARCHAR(50) DEFAULT NULL,
            risk VARCHAR(20) NOT NULL,
            score INTEGER NOT NULL,
            analyst_rating VARCHAR(50) DEFAULT NULL,
            thesis CLOB DEFAULT NULL,
            catalysts CLOB DEFAULT NULL,
            key_risks CLOB DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4B365660639F7744 ON stock (symbol)');

        $this->addSql('CREATE TABLE watchlist (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            added_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_340388D3639F7744 ON watchlist (symbol)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stock');
        $this->addSql('DROP TABLE watchlist');
    }
}
