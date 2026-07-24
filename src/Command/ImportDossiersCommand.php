<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les dossiers législatifs depuis le dépôt des Tricoteuses.
 *
 * Le code de procédure parlementaire est ce qui distingue un texte du
 * Gouvernement (projet de loi) d'un texte parlementaire (proposition de loi) :
 * c'est lui qui fonde le taux de soutien au gouvernement affiché sur les pages
 * de groupe.
 *
 * @see \App\Entity\Dossier::PROCEDURES_GOUVERNEMENT
 */
#[AsCommand(
    name: 'app:import:dossiers',
    description: 'Importe les dossiers législatifs depuis le dépôt des Tricoteuses.',
)]
class ImportDossiersCommand extends ImportTricoteusesCommand
{
    private const COLONNES = [
        'dossier_id', 'legislature', 'titre', 'titre_chemin',
        'procedure_parlementaire', 'procedure_code',
    ];

    protected function depotParDefaut(): string
    {
        return 'dossiers';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tout = (bool) $input->getOption('tout');
        $chemin = $this->chemin($input);

        $io->title('Import des dossiers législatifs');
        $io->text($this->incremental($chemin, $tout) ? 'Régime incrémental : seuls les dossiers modifiés.' : 'Import complet du dépôt.');

        $lus = 0;
        $lot = [];

        foreach ($this->fichiers($chemin, 'dossiers', $tout) as $fichier) {
            $dossier = $this->lisJson($fichier);
            if ($dossier === null || !isset($dossier['uid'])) {
                continue;
            }
            ++$lus;

            $lot[] = [
                $dossier['uid'],
                $this->entier($dossier['legislature'] ?? null),
                $dossier['titreDossier']['titre'] ?? null,
                $dossier['titreDossier']['titreChemin'] ?? null,
                $dossier['procedureParlementaire']['libelle'] ?? null,
                $this->entier($dossier['procedureParlementaire']['code'] ?? null),
            ];

            if (\count($lot) >= self::TAILLE_LOT) {
                $this->upsert('dossier', self::COLONNES, $lot, \array_slice(self::COLONNES, 1));
                $lot = [];
            }
        }

        $this->upsert('dossier', self::COLONNES, $lot, \array_slice(self::COLONNES, 1));

        $io->success(sprintf(
            '%d dossier%s traité%s — %d en base.',
            $lus,
            $lus > 1 ? 's' : '',
            $lus > 1 ? 's' : '',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dossier'),
        ));

        return Command::SUCCESS;
    }
}
