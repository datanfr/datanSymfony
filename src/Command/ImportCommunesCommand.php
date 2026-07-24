<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe la géographie électorale — départements et communes — depuis deux
 * exports de la base de l'application d'origine.
 *
 * Ces données ne sont pas dans les dépôts des Tricoteuses : l'open data de
 * l'Assemblée s'arrête au numéro de circonscription et ne dit ni quelles
 * communes la composent, ni comment le site écrit « du Nord ». La base de
 * production les tient dans `departement`, `circos` (découpage) et `cities`
 * (référentiel INSEE), et c'est de là qu'elles viennent.
 *
 * Elle n'est pas joignable depuis l'application — le port 3307 de l'hôte est
 * pris par la base de développement, la production ne vit que dans le conteneur
 * `datan-db`. On passe donc par deux fichiers, régénérés ainsi :
 *
 * ```
 * docker exec datan-db mariadb -h 127.0.0.1 -u datan -pdatan datan \
 *   --default-character-set=utf8mb4 -B -e "
 *   SELECT departement_code, departement_nom, slug, libelle_1, libelle_2, region
 *   FROM departement ORDER BY departement_code;" > var/legacy/departements.tsv
 *
 * docker exec datan-db mariadb -h 127.0.0.1 -u datan -pdatan datan \
 *   --default-character-set=utf8mb4 -B -e "
 *   SELECT c.insee, c.dpt, c.commune_nom, c.commune_slug,
 *          MAX(ci.population) AS population,
 *          GROUP_CONCAT(DISTINCT c.circo ORDER BY CAST(c.circo AS UNSIGNED)) AS circos,
 *          MAX(i.postal) AS code_postal,
 *          MAX(cin.pop2012) AS population_2012
 *   FROM circos c
 *   LEFT JOIN cities ci ON ci.code_insee = c.insee
 *   LEFT JOIN insee i ON i.insee = c.insee
 *   LEFT JOIN cities_infos cin ON cin.insee = c.insee
 *   GROUP BY c.insee, c.dpt, c.commune_nom, c.commune_slug
 *   ORDER BY c.dpt, c.commune_nom;" > var/legacy/communes.tsv
 *
 * docker exec datan-db mariadb -h 127.0.0.1 -u datan -pdatan datan \
 *   --default-character-set=utf8mb4 -B -e "
 *   SELECT DISTINCT a.insee, a.adjacente
 *   FROM cities_adjacentes a JOIN circos c ON c.insee = a.adjacente
 *   ORDER BY a.insee, a.adjacente;" > var/legacy/communes_adjacentes.tsv
 * ```
 *
 * Le maire de chaque commune (`cities_mayors`) manque à l'appel : la table est
 * vide dans la copie dont nous disposons, alors que le site en affiche un. Le
 * paragraphe correspondant de la fiche de ville reste donc non porté.
 */
#[AsCommand(
    name: 'app:import:communes',
    description: 'Importe les départements et les communes depuis un export de la base de production.',
)]
class ImportCommunesCommand extends ImportLegacyCommand
{
    private const COLONNES_DEPARTEMENT = ['code', 'nom', 'slug', 'libelle_dans', 'libelle_de', 'region'];

    private const COLONNES_COMMUNE = ['code_insee', 'nom', 'slug', 'population', 'population2012', 'code_postal', 'departement_id'];

    private const COLONNES_CIRCONSCRIPTION = ['commune_id', 'circonscription'];

    private const COLONNES_ADJACENTE = ['commune_id', 'adjacente_id'];

    protected function configure(): void
    {
        $this
            ->addOption('departements', null, InputOption::VALUE_REQUIRED, 'Export TSV de la table departement', 'var/legacy/departements.tsv')
            ->addOption('communes', null, InputOption::VALUE_REQUIRED, 'Export TSV des communes et de leurs circonscriptions', 'var/legacy/communes.tsv')
            ->addOption('adjacentes', null, InputOption::VALUE_REQUIRED, 'Export TSV des couples de communes limitrophes', 'var/legacy/communes_adjacentes.tsv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Import de la géographie électorale');

        $departements = $this->importeDepartements((string) $input->getOption('departements'));
        $io->text(sprintf('%d départements.', $departements));

        [$communes, $circonscriptions] = $this->importeCommunes((string) $input->getOption('communes'));
        $io->text(sprintf('%d communes, %d rattachements à une circonscription.', $communes, $circonscriptions));

        $io->text(sprintf('%d couples de communes limitrophes.', $this->importeAdjacentes((string) $input->getOption('adjacentes'))));

        $io->success(sprintf(
            '%d départements, %d communes, %d rattachements et %d voisinages en base.',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM departement'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commune'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commune_circonscription'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commune_adjacente'),
        ));

        return Command::SUCCESS;
    }

    private function importeDepartements(string $fichier): int
    {
        // L'orthographe de l'Assemblée l'emporte sur celle du jeu `villes_fr`,
        // qui écrit « Côte-d'or », « Côtes-d'armor », « Val-d'oise » et
        // « Corse-du-sud ». Le code sert de pivot et passe en capitales pour
        // s'aligner sur `mandat` (« 2A » et non « 2a »).
        $nomsAssemblee = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT DISTINCT departement_code, departement_nom FROM mandat WHERE departement_code IS NOT NULL') as $code => $nom) {
            $nomsAssemblee[strtoupper((string) $code)] = $nom;
        }

        $lot = [];

        foreach ($this->lignes($fichier) as $ligne) {
            [$code, $nom, $slug, $dans, $de, $region] = array_pad($ligne, 6, null);
            $code = strtoupper((string) $code);

            $lot[] = [$code, $nomsAssemblee[$code] ?? $nom, $slug, $dans, $de, $region];
        }

        $this->upsert('departement', self::COLONNES_DEPARTEMENT, $lot, ['nom', 'slug', 'libelle_dans', 'libelle_de', 'region']);

        return \count($lot);
    }

    /**
     * @return array{0: int, 1: int} communes et rattachements écrits
     */
    private function importeCommunes(string $fichier): array
    {
        $idDepartements = $this->connection->fetchAllKeyValue('SELECT code, id FROM departement');

        /** @var array<string, list<int>> $circosParInsee */
        $circosParInsee = [];
        $lot = [];
        $communes = 0;

        $misAJour = ['nom', 'slug', 'population', 'population2012', 'code_postal', 'departement_id'];

        foreach ($this->lignes($fichier) as $ligne) {
            [$insee, $departement, $nom, $slug, $population, $circos, $codePostal, $population2012] = array_pad($ligne, 8, null);

            $idDepartement = $idDepartements[strtoupper((string) $departement)] ?? null;

            if ($idDepartement === null) {
                continue;
            }

            $lot[] = [
                $insee,
                $nom,
                $slug,
                $population === null ? null : (int) $population,
                $population2012 === null ? null : (int) $population2012,
                // Le référentiel stocke le code postal en entier : « 1500 » pour
                // l'Ain, qu'il faut relire « 01500 ». Les 48 communes à codes
                // multiples les portent déjà sur cinq chiffres chacun, le
                // remplissage ne les touche donc pas.
                $codePostal === null ? null : str_pad($codePostal, 5, '0', \STR_PAD_LEFT),
                $idDepartement,
            ];
            ++$communes;

            if ($circos !== null && $circos !== '') {
                $circosParInsee[$insee] = array_map('intval', explode(',', $circos));
            }

            if (\count($lot) >= self::TAILLE_LOT) {
                $this->upsert('commune', self::COLONNES_COMMUNE, $lot, $misAJour);
                $lot = [];
            }
        }

        $this->upsert('commune', self::COLONNES_COMMUNE, $lot, $misAJour);

        return [$communes, $this->importeCirconscriptions($circosParInsee)];
    }

    /**
     * @param array<string, list<int>> $circosParInsee
     */
    private function importeCirconscriptions(array $circosParInsee): int
    {
        // Les identifiants de commune ne sont connus qu'une fois les communes
        // écrites : d'où cette seconde passe, sur une carte tenue en mémoire —
        // 36 000 entrées, sans commune mesure avec les mandats.
        $idCommunes = $this->connection->fetchAllKeyValue('SELECT code_insee, id FROM commune');

        $lot = [];
        $rattachements = 0;

        foreach ($circosParInsee as $insee => $circos) {
            $idCommune = $idCommunes[$insee] ?? null;

            if ($idCommune === null) {
                continue;
            }

            foreach ($circos as $circonscription) {
                $lot[] = [$idCommune, $circonscription];
                ++$rattachements;
            }

            if (\count($lot) >= self::TAILLE_LOT) {
                $this->upsert('commune_circonscription', self::COLONNES_CIRCONSCRIPTION, $lot, []);
                $lot = [];
            }
        }

        $this->upsert('commune_circonscription', self::COLONNES_CIRCONSCRIPTION, $lot, []);

        return $rattachements;
    }

    /**
     * Communes limitrophes. L'export ne retient que les voisines connues du
     * découpage électoral, et les deux sens du couple y figurent déjà.
     */
    private function importeAdjacentes(string $fichier): int
    {
        $idCommunes = $this->connection->fetchAllKeyValue('SELECT code_insee, id FROM commune');

        $lot = [];
        $couples = 0;

        foreach ($this->lignes($fichier) as [$insee, $adjacente]) {
            $commune = $idCommunes[$insee] ?? null;
            $voisine = $idCommunes[$adjacente] ?? null;

            if ($commune === null || $voisine === null) {
                continue;
            }

            $lot[] = [$commune, $voisine];
            ++$couples;

            if (\count($lot) >= self::TAILLE_LOT) {
                $this->upsert('commune_adjacente', self::COLONNES_ADJACENTE, $lot, []);
                $lot = [];
            }
        }

        $this->upsert('commune_adjacente', self::COLONNES_ADJACENTE, $lot, []);

        return $couples;
    }

}
