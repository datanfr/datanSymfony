<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Attributs de commune servant la fiche de ville : code postal, population de
 * 2012 (pour l'évolution sur dix ans) et communes limitrophes.
 */
final class Version20260722101210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Code postal, population 2012 et communes limitrophes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commune_adjacente (commune_id INT NOT NULL, adjacente_id INT NOT NULL, INDEX IDX_C1777978131A4F72 (commune_id), INDEX IDX_C1777978894CF5C0 (adjacente_id), PRIMARY KEY(commune_id, adjacente_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commune_adjacente ADD CONSTRAINT FK_C1777978131A4F72 FOREIGN KEY (commune_id) REFERENCES commune (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commune_adjacente ADD CONSTRAINT FK_C1777978894CF5C0 FOREIGN KEY (adjacente_id) REFERENCES commune (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commune ADD population2012 INT DEFAULT NULL, ADD code_postal VARCHAR(40) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE commune_adjacente DROP FOREIGN KEY FK_C1777978131A4F72
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commune_adjacente DROP FOREIGN KEY FK_C1777978894CF5C0
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE commune_adjacente
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE commune DROP population2012, DROP code_postal
        SQL);
    }
}
