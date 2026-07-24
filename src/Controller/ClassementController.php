<?php

namespace App\Controller;

use App\AgeFrance;
use App\CouleurGroupe;
use App\Enum\TypeClassement;
use App\FamilleSocioPro;
use App\Legislature;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages de statistiques et de classements, portées du contrôleur Stats de
 * l'application CodeIgniter d'origine (routes `statistiques` et
 * `statistiques/(:any)`).
 *
 * Aucun classement n'est calculé ici : tout est lu dans la table `classement`,
 * remplie par {@see \App\Command\CalculClassementsCommand}. Ne restent à la
 * charge des pages que des dénombrements sur les 577 députés en exercice —
 * répartition par famille socio-professionnelle, métiers les plus fréquents —
 * qui ne touchent ni `vote` ni `vote_groupe`.
 */
class ClassementController extends AbstractController
{
    private const CACHE_TTL = 3600;

    /** Sigle des non-inscrits, écartés des comparaisons entre groupes. */
    private const NON_INSCRITS = 'NI';

    /**
     * En deçà de ce nombre de scrutins solennels, la législature est jugée trop
     * jeune pour que le score de participation qui s'y rapporte veuille dire
     * quelque chose : le site met alors en avant tous les scrutins.
     */
    private const SEUIL_SOLENNELS = 30;

    /**
     * Prédicat « ce député siège encore », à substituer de l'alias de `depute`
     * et à accompagner d'un paramètre `:legislature`.
     *
     * `depute.groupe_id` désigne le dernier groupe connu, y compris pour les
     * députés partis en cours de mandat : s'y fier gonflerait les effectifs.
     * Le statut se lit sur les mandats, comme sur la page d'un député.
     */
    private const EN_EXERCICE = 'EXISTS (SELECT 1 FROM mandat m
                                         WHERE m.depute_id = %s.id
                                           AND m.legislature = :legislature
                                           AND m.date_fin IS NULL)';

    /** Effectif d'un groupe : ses membres encore en exercice, `g` étant l'alias du groupe. */
    private const EFFECTIF_SQL = '(SELECT COUNT(*) FROM depute dep
                                   WHERE dep.groupe_id = g.id
                                     AND EXISTS (SELECT 1 FROM mandat m
                                                 WHERE m.depute_id = dep.id
                                                   AND m.legislature = g.legislature
                                                   AND m.date_fin IS NULL))';

    /** Part de femmes dans la population française, en pourcentage. */
    private const FEMMES_SOCIETE = 52;

    /** Les neuf classements publiés, et le titre de chacun. */
    public const PAGES = [
        'deputes-age' => 'L\'âge des députés',
        'groupes-age' => 'L\'âge moyen au sein des groupes',
        'groupes-feminisation' => 'Le taux de féminisation des groupes parlementaires',
        'deputes-loyaute' => 'La proximité des députés à leur groupe',
        'groupes-cohesion' => 'La cohésion des groupes parlementaires',
        'deputes-participation' => 'La participation des députés',
        'groupes-participation' => 'La participation des groupes politiques',
        'deputes-origine-sociale' => 'L\'origine sociale des députés',
        'groupes-origine-sociale' => 'La représentativité sociale des groupes politiques',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * L'ancienne adresse de la rubrique, indexée depuis des années
     * (routes.php:236 → redirection/redir/statistiques). L'origine y répond
     * 307, le `redirect()` de CodeIgniter ne précisant pas de code — un
     * déplacement définitif se dit en 301 : défaut corrigé, pas choix.
     */
    #[Route('/classements', name: 'classements_ancienne_adresse', methods: ['GET'])]
    public function ancienneAdresse(): Response
    {
        return $this->redirectToRoute('classement_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/statistiques', name: 'classement_index', methods: ['GET'])]
    public function index(): Response
    {
        $ages = $this->classementDeputes(TypeClassement::DeputesAge);
        $loyaute = $this->classementDeputes(TypeClassement::DeputesLoyaute);
        $participation = $this->classementDeputes($this->typeParticipationDeputes());
        $agesGroupes = $this->classementGroupes(TypeClassement::GroupesAge);
        $feminisation = $this->classementGroupes(TypeClassement::GroupesFeminisation);
        $cohesion = $this->sansNonInscrits($this->classementGroupes(TypeClassement::GroupesCohesion));
        $participationGroupes = $this->sansNonInscrits(
            $this->classementGroupes($this->typeParticipationGroupes()),
        );
        $rose = $this->classementGroupes(TypeClassement::GroupesOrigineSociale);
        $familles = $this->familles();
        $cadres = $this->partsFamilleParGroupe(FamilleSocioPro::CADRES);

        $response = $this->render('classement/index.html.twig', [
            'title' => 'L\'Assemblée nationale en chiffres',
            'age_moyen' => $this->moyenneAges(),
            'deputes_plus_ages' => \array_slice($ages, 0, 3),
            'deputes_plus_jeunes' => \array_slice($ages, -3),
            'groupes_age' => $this->extremes($agesGroupes, 'Le plus âgé', 'Le plus jeune', fn (array $g) => $g['score_arrondi'] . ' ans'),
            'femmes' => $this->femmes(),
            'femmes_historique' => $this->historiqueFemmes(),
            'groupes_femmes_plus' => \array_slice($feminisation, 0, 3),
            'groupes_femmes_moins' => \array_slice($feminisation, -3),
            'loyaute_moyenne' => $this->moyenne(TypeClassement::DeputesLoyaute),
            'deputes_plus_loyaux' => \array_slice($loyaute, 0, 3),
            'deputes_moins_loyaux' => \array_slice($loyaute, -3),
            'groupes_cohesion' => $this->extremes($cohesion, 'Le plus uni', 'Le plus divisé', fn (array $g) => number_format($g['score'], 2, ',', ' ')),
            'participation_moyenne' => $this->moyenne($this->typeParticipationDeputes()),
            'deputes_plus_actifs' => \array_slice($participation, 0, 3),
            'deputes_moins_actifs' => \array_slice($participation, -3),
            'groupes_participation' => $this->extremes($participationGroupes, 'Vote le plus', 'Vote le moins', fn (array $g) => $g['pourcentage'] . ' %'),
            'familles' => $familles,
            'famille_cadres' => $familles[FamilleSocioPro::CADRES],
            'groupes_cadres' => $this->extremes($cadres, 'Le plus de cadres', 'Le moins de cadres', fn (array $g) => $g['pourcentage'] . ' %'),
            'groupes_rose' => $this->extremes($rose, 'Le plus représentatif', 'Le moins représentatif', fn (array $g) => number_format($g['score'], 3, ',', ' ')),
            'fil_ariane' => [
                ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
                ['nom' => 'Nos statistiques', 'url' => $this->generateUrl('classement_index')],
            ],
        ]);

        return $this->cachee($response);
    }

    #[Route('/statistiques/{page}', name: 'classement_individual', requirements: ['page' => '[a-z\-]+'], methods: ['GET'])]
    public function individual(string $page): Response
    {
        if (!isset(self::PAGES[$page])) {
            throw $this->createNotFoundException('Classement introuvable.');
        }

        $donnees = match ($page) {
            'deputes-age' => $this->deputesAge(),
            'groupes-age' => $this->groupesAge(),
            'groupes-feminisation' => $this->groupesFeminisation(),
            'deputes-loyaute' => $this->deputesLoyaute(),
            'groupes-cohesion' => $this->groupesCohesion(),
            'deputes-participation' => $this->deputesParticipation(),
            'groupes-participation' => $this->groupesParticipation(),
            'deputes-origine-sociale' => $this->deputesOrigineSociale(),
            'groupes-origine-sociale' => $this->groupesOrigineSociale(),
        };

        $response = $this->render('classement/' . $page . '.html.twig', [
            ...$donnees,
            'page' => $page,
            'title' => self::PAGES[$page],
            'pages' => self::PAGES,
            'fil_ariane' => [
                ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
                ['nom' => 'Nos statistiques', 'url' => $this->generateUrl('classement_index')],
                ['nom' => self::PAGES[$page], 'url' => $this->generateUrl('classement_individual', ['page' => $page])],
            ],
        ]);

        return $this->cachee($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function deputesAge(): array
    {
        $deputes = $this->classementDeputes(TypeClassement::DeputesAge);
        $moyenne = $this->moyenneAges();
        $ecart = (int) round($moyenne - AgeFrance::ELIGIBLES);

        return [
            'deputes' => $deputes,
            'age_moyen' => (int) round($moyenne),
            'age_moyen_france' => (int) round(AgeFrance::ELIGIBLES),
            'ecart' => $this->ecartEnMots($ecart),
            'plus_age' => $deputes[0] ?? null,
            'plus_jeune' => $deputes !== [] ? end($deputes) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupesAge(): array
    {
        $groupes = $this->classementGroupes(TypeClassement::GroupesAge);

        return [
            'groupes' => $groupes,
            'age_moyen_france' => (int) round(AgeFrance::ELIGIBLES),
            'plus_age' => $groupes[0] ?? null,
            'plus_jeune' => $groupes !== [] ? end($groupes) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupesFeminisation(): array
    {
        $groupes = $this->classementGroupes(TypeClassement::GroupesFeminisation);
        $femmes = $this->femmes();

        return [
            'groupes' => $groupes,
            'femmes' => $femmes,
            'femmes_societe' => self::FEMMES_SOCIETE,
            'ecart_societe' => abs(self::FEMMES_SOCIETE - $femmes['pourcentage']),
            'plus_feminise' => $groupes[0] ?? null,
            'moins_feminise' => $groupes !== [] ? end($groupes) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deputesLoyaute(): array
    {
        $deputes = $this->classementDeputes(TypeClassement::DeputesLoyaute);

        return [
            'deputes' => $deputes,
            'plus_loyal' => $deputes[0] ?? null,
            'plus_rebelle' => $deputes !== [] ? end($deputes) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupesCohesion(): array
    {
        $groupes = $this->classementGroupes(TypeClassement::GroupesCohesion);

        // Les non-inscrits figurent au tableau — l'application d'origine les y
        // laisse — mais pas dans les cartes, qui opposent deux groupes.
        $comparables = $this->sansNonInscrits($groupes);

        return [
            'groupes' => $groupes,
            'cohesion_moyenne' => $this->moyenne(TypeClassement::GroupesCohesion),
            'plus_uni' => $comparables[0] ?? null,
            'plus_divise' => $comparables !== [] ? end($comparables) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deputesParticipation(): array
    {
        $solennels = $this->classementDeputes(TypeClassement::DeputesParticipation);
        $tous = $this->classementDeputes(TypeClassement::DeputesParticipationTous);
        $miseEnAvant = $this->assezDeSolennels() ? $solennels : $tous;

        return [
            'solennels' => $solennels,
            'tous' => $tous,
            'moyenne_solennels' => $this->moyenne(TypeClassement::DeputesParticipation),
            'moyenne_tous' => $this->moyenne(TypeClassement::DeputesParticipationTous),
            'nombre_solennels' => $this->nombreSolennels(),
            'seuil_solennels' => self::SEUIL_SOLENNELS,
            'assez_de_solennels' => $this->assezDeSolennels(),
            'nombre_scrutins' => $this->nombreScrutins(),
            'plus_actif' => $miseEnAvant[0] ?? null,
            'moins_actif' => $miseEnAvant !== [] ? end($miseEnAvant) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupesParticipation(): array
    {
        $solennels = $this->classementGroupes(TypeClassement::GroupesParticipation);
        $tous = $this->classementGroupes(TypeClassement::GroupesParticipationTous);
        $miseEnAvant = $this->sansNonInscrits($this->assezDeSolennels() ? $solennels : $tous);

        return [
            'solennels' => $solennels,
            'tous' => $tous,
            'moyenne_solennels' => $this->moyenne(TypeClassement::GroupesParticipation),
            'moyenne_tous' => $this->moyenne(TypeClassement::GroupesParticipationTous),
            'nombre_solennels' => $this->nombreSolennels(),
            'seuil_solennels' => self::SEUIL_SOLENNELS,
            'assez_de_solennels' => $this->assezDeSolennels(),
            'nombre_scrutins' => $this->nombreScrutins(),
            'plus_actif' => $miseEnAvant[0] ?? null,
            'moins_actif' => $miseEnAvant !== [] ? end($miseEnAvant) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deputesOrigineSociale(): array
    {
        $familles = $this->familles();

        return [
            'familles' => $familles,
            'famille_cadres' => $familles[FamilleSocioPro::CADRES],
            'metiers' => $this->metiers(10),
            'deputes' => $this->professionsDeputes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupesOrigineSociale(): array
    {
        $groupes = $this->classementGroupes(TypeClassement::GroupesOrigineSociale);
        $familles = $this->familles();

        return [
            'groupes' => $groupes,
            'plus_representatif' => $groupes[0] ?? null,
            'famille_cadres' => $familles[FamilleSocioPro::CADRES],
            'parts' => $this->partsParGroupe(),
            'noms_familles' => array_keys(FamilleSocioPro::population()),
        ];
    }

    /**
     * Une ligne de classement par député, dans l'ordre du palmarès.
     *
     * `rang` comporte des ex æquo et donc des trous : il ne suffit pas à
     * ordonner. Les lignes ayant été écrites dans l'ordre du classement,
     * l'identifiant les départage exactement comme le calcul l'a fait.
     *
     * @return list<array<string, mixed>>
     */
    private function classementDeputes(TypeClassement $type): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT c.rang, c.score, c.numerateur, c.denominateur,
                    d.mp_id, d.firstname, d.lastname, d.slug, d.dpt_slug, d.civilite,
                    d.departement_nom, d.departement_code,
                    g.legislature, g.libelle AS groupe_libelle, g.libelle_abrev AS groupe_abrev,
                    ' . CouleurGroupe::SQL . ' AS groupe_couleur
             FROM classement c
             JOIN depute d ON d.id = c.depute_id
             LEFT JOIN groupe g ON g.id = d.groupe_id
             WHERE c.type = :type AND c.legislature = :legislature
             ORDER BY c.rang, c.id',
            ['type' => $type->value, 'legislature' => Legislature::COURANTE],
        );

        return array_map($this->decore(...), $lignes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classementGroupes(TypeClassement $type): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT c.rang, c.score, c.numerateur, c.denominateur,
                    g.legislature, g.libelle, g.libelle_abrev,
                    ' . CouleurGroupe::SQL . ' AS couleur,
                    ' . self::EFFECTIF_SQL . ' AS effectif
             FROM classement c
             JOIN groupe g ON g.id = c.groupe_id
             WHERE c.type = :type AND c.legislature = :legislature
             ORDER BY c.rang, c.id',
            ['type' => $type->value, 'legislature' => Legislature::COURANTE],
        );

        return array_map($this->decore(...), $lignes);
    }

    /**
     * Ajoute à une ligne de classement les formes sous lesquelles les vues
     * l'affichent : le score en nombre, arrondi, et en pourcentage.
     *
     * @param array<string, mixed> $ligne
     *
     * @return array<string, mixed>
     */
    private function decore(array $ligne): array
    {
        $score = (float) $ligne['score'];

        return [
            ...$ligne,
            'rang' => (int) $ligne['rang'],
            'score' => $score,
            'score_arrondi' => (int) round($score),
            'pourcentage' => (int) round($score * 100),
            'numerateur' => $ligne['numerateur'] !== null ? (int) $ligne['numerateur'] : null,
            'denominateur' => $ligne['denominateur'] !== null ? (int) $ligne['denominateur'] : null,
        ];
    }

    /**
     * Les deux bouts d'un classement, tels que les affichent les cartes en
     * vis-à-vis : un titre, le groupe, et le score mis en forme.
     *
     * @param list<array<string, mixed>> $classement
     * @param callable(array<string, mixed>): string $stat
     *
     * @return array{premier: array<string, mixed>, dernier: array<string, mixed>}|null
     */
    private function extremes(array $classement, string $titrePremier, string $titreDernier, callable $stat): ?array
    {
        if ($classement === []) {
            return null;
        }

        $premier = $classement[0];
        $dernier = end($classement);

        return [
            'premier' => ['titre' => $titrePremier, 'groupe' => $premier, 'stat' => $stat($premier)],
            'dernier' => ['titre' => $titreDernier, 'groupe' => $dernier, 'stat' => $stat($dernier)],
        ];
    }

    /**
     * @param list<array<string, mixed>> $groupes
     *
     * @return list<array<string, mixed>>
     */
    private function sansNonInscrits(array $groupes): array
    {
        return array_values(array_filter(
            $groupes,
            static fn (array $g) => $g['libelle_abrev'] !== self::NON_INSCRITS,
        ));
    }

    private function moyenne(TypeClassement $type): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT AVG(score) FROM classement WHERE type = :type AND legislature = :legislature',
            ['type' => $type->value, 'legislature' => Legislature::COURANTE],
        );
    }

    /**
     * L'âge moyen se recalcule sur les dates de naissance plutôt que sur la
     * moyenne des scores du classement, qui sont des années révolues arrondies.
     */
    private function moyenneAges(): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT AVG(TIMESTAMPDIFF(YEAR, ps.date_naissance, CURDATE()))
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND ps.date_naissance IS NOT NULL
               AND ' . sprintf(self::EN_EXERCICE, 'd'),
            ['legislature' => Legislature::COURANTE],
        );
    }

    /**
     * @return array{nombre: int, pourcentage: int, effectif: int}
     */
    private function femmes(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS effectif, SUM(d.civilite = :mme) AS femmes
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             WHERE g.legislature = :legislature AND ' . sprintf(self::EN_EXERCICE, 'd'),
            ['legislature' => Legislature::COURANTE, 'mme' => 'Mme'],
        ) ?: [];

        $effectif = (int) ($row['effectif'] ?? 0);
        $femmes = (int) ($row['femmes'] ?? 0);

        return [
            'nombre' => $femmes,
            'effectif' => $effectif,
            'pourcentage' => $effectif > 0 ? (int) round($femmes / $effectif * 100) : 0,
        ];
    }

    /**
     * Part de femmes à l'Assemblée au fil des législatures. Les valeurs passées
     * sont celles de l'application d'origine, qui les tient en dur — les
     * effectifs des législatures antérieures à la 14e ne sont pas en base.
     *
     * @return list<array{legislature: int, debut: string, fin: string, pourcentage: int}>
     */
    private function historiqueFemmes(): array
    {
        $historique = [
            ['legislature' => 11, 'debut' => '1997', 'fin' => '2002', 'pourcentage' => 13],
            ['legislature' => 12, 'debut' => '2002', 'fin' => '2007', 'pourcentage' => 13],
            ['legislature' => 13, 'debut' => '2007', 'fin' => '2012', 'pourcentage' => 20],
            ['legislature' => 14, 'debut' => '2012', 'fin' => '2017', 'pourcentage' => 27],
            ['legislature' => 15, 'debut' => '2017', 'fin' => '2022', 'pourcentage' => 39],
            ['legislature' => 16, 'debut' => '2022', 'fin' => '2024', 'pourcentage' => 37],
        ];

        $historique[] = [
            'legislature' => Legislature::COURANTE,
            'debut' => '2024',
            'fin' => '2029',
            'pourcentage' => $this->femmes()['pourcentage'],
        ];

        return \array_slice($historique, -6);
    }

    /**
     * Répartition des députés en exercice dans les huit familles
     * socio-professionnelles de l'INSEE, rapportée à leur poids dans la
     * population. Un dénombrement sur 577 lignes : il n'y a rien à précalculer.
     *
     * @return array<string, array{famille: string, nombre: int, part: float, population: float}>
     */
    private function familles(): array
    {
        $effectif = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM depute d JOIN groupe g ON g.id = d.groupe_id
             WHERE g.legislature = :legislature AND ' . sprintf(self::EN_EXERCICE, 'd'),
            ['legislature' => Legislature::COURANTE],
        );

        $comptes = $this->connection->fetchAllKeyValue(
            'SELECT ps.fam_soc_pro, COUNT(*)
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND ps.fam_soc_pro IS NOT NULL
               AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY ps.fam_soc_pro',
            ['legislature' => Legislature::COURANTE],
        );

        $familles = [];

        foreach (FamilleSocioPro::population() as $famille => $population) {
            $nombre = (int) ($comptes[$famille] ?? 0);

            $familles[$famille] = [
                'famille' => $famille,
                // Les libellés sont longs : le graphique les reçoit découpés en
                // lignes, Chart.js empilant les étiquettes qu'on lui donne en
                // tableau. Même découpe que `word_wrap($famille, 25)` d'origine.
                'lignes' => explode("\n", wordwrap($famille, 25, "\n")),
                'nombre' => $nombre,
                'part' => $effectif > 0 ? round($nombre / $effectif * 100, 2) : 0.0,
                'population' => $population,
            ];
        }

        return $familles;
    }

    /**
     * Part de chaque groupe dans une famille socio-professionnelle donnée,
     * du plus fourni au moins fourni.
     *
     * @return list<array<string, mixed>>
     */
    private function partsFamilleParGroupe(string $famille): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT g.legislature, g.libelle, g.libelle_abrev,
                    ' . CouleurGroupe::SQL . ' AS couleur,
                    COUNT(ps.id) AS nombre,
                    ' . self::EFFECTIF_SQL . ' AS effectif
             FROM groupe g
             JOIN depute d ON d.groupe_id = g.id
             LEFT JOIN profil_social ps ON ps.depute_id = d.id AND ps.fam_soc_pro = :famille
             WHERE g.legislature = :legislature AND g.date_fin IS NULL
               AND g.libelle_abrev <> :ni AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY g.id, g.legislature, g.libelle, g.libelle_abrev, g.couleur
             ORDER BY COUNT(ps.id) / ' . self::EFFECTIF_SQL . ' DESC',
            ['famille' => $famille, 'legislature' => Legislature::COURANTE, 'ni' => self::NON_INSCRITS],
        );

        return array_map(static function (array $ligne): array {
            $effectif = (int) $ligne['effectif'];
            $nombre = (int) $ligne['nombre'];

            return [
                ...$ligne,
                'nombre' => $nombre,
                'effectif' => $effectif,
                'pourcentage' => $effectif > 0 ? (int) round($nombre / $effectif * 100) : 0,
            ];
        }, $lignes);
    }

    /**
     * Part de chaque famille socio-professionnelle dans chaque groupe, en
     * pourcentage : le tableau croisé de la page « représentativité ».
     *
     * @return array<string, array<string, int>>
     */
    private function partsParGroupe(): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT g.libelle_abrev, ps.fam_soc_pro, COUNT(*) AS nombre,
                    ' . self::EFFECTIF_SQL . ' AS effectif
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL
               AND g.libelle_abrev <> :ni AND ps.fam_soc_pro IS NOT NULL
               AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY g.id, g.libelle_abrev, ps.fam_soc_pro
             ORDER BY g.libelle_abrev',
            ['legislature' => Legislature::COURANTE, 'ni' => self::NON_INSCRITS],
        );

        $parts = [];

        foreach ($lignes as $ligne) {
            $effectif = (int) $ligne['effectif'];

            $parts[$ligne['libelle_abrev']][$ligne['fam_soc_pro']] = $effectif > 0
                ? (int) round((int) $ligne['nombre'] / $effectif * 100)
                : 0;
        }

        return $parts;
    }

    /**
     * Les métiers les plus représentés à l'Assemblée. Les libellés déclarés
     * sont regroupés comme dans l'application d'origine — « Avocat » réunit
     * « Avocat », « Avocate », « Avocat associé »… — sans quoi le classement
     * se disperserait en variantes d'un même métier.
     *
     * @return list<array{metier: string, nombre: int}>
     */
    private function metiers(int $limite): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT CASE
                        WHEN ps.metier LIKE :avocat THEN \'Avocat\'
                        WHEN ps.metier LIKE :medecin THEN \'Médecin\'
                        WHEN ps.metier LIKE :cadre THEN \'Cadre\'
                        WHEN ps.metier LIKE :professeur OR ps.metier LIKE :conference THEN \'Professeur\'
                        WHEN ps.metier LIKE :chef THEN \'Chef d\'\'entreprise\'
                        WHEN ps.metier LIKE :agriculteur THEN \'Agriculteur\'
                        WHEN ps.metier LIKE :pharmacien THEN \'Pharmacien\'
                        ELSE ps.metier
                    -- L\'alias ne peut pas s\'appeler `metier` : dans un GROUP BY,
                    -- MariaDB donne la priorité à la colonne du même nom et
                    -- regrouperait sur les libellés bruts, sans les réunir.
                    END AS metier_groupe,
                    COUNT(*) AS nombre
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND ps.metier IS NOT NULL
               AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY metier_groupe
             ORDER BY nombre DESC, metier_groupe
             LIMIT ' . $limite,
            [
                'legislature' => Legislature::COURANTE,
                'avocat' => '%vocat%',
                'medecin' => 'Médecin%',
                'cadre' => '%adre%',
                'professeur' => 'Professeur%',
                'conference' => '%conférence%',
                'chef' => '%hef d\'entreprise%',
                'agriculteur' => '%griculteur%',
                'pharmacien' => '%harmacien%',
            ],
        );

        return array_map(
            static fn (array $l) => ['metier' => $l['metier_groupe'], 'nombre' => (int) $l['nombre']],
            $lignes,
        );
    }

    /**
     * Métier et famille socio-professionnelle de chaque député en exercice.
     *
     * @return list<array<string, mixed>>
     */
    private function professionsDeputes(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.firstname, d.lastname, d.slug, d.dpt_slug,
                    g.libelle_abrev AS groupe_abrev,
                    ps.metier, ps.fam_soc_pro
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             LEFT JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             ORDER BY d.lastname, d.firstname',
            ['legislature' => Legislature::COURANTE],
        );
    }

    private function nombreSolennels(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM scrutin WHERE legislature = :legislature AND code_type_vote = :sps',
            ['legislature' => Legislature::COURANTE, 'sps' => 'SPS'],
        );
    }

    private function nombreScrutins(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM scrutin WHERE legislature = :legislature',
            ['legislature' => Legislature::COURANTE],
        );
    }

    private function assezDeSolennels(): bool
    {
        return $this->nombreSolennels() > self::SEUIL_SOLENNELS;
    }

    private function typeParticipationDeputes(): TypeClassement
    {
        return $this->assezDeSolennels()
            ? TypeClassement::DeputesParticipation
            : TypeClassement::DeputesParticipationTous;
    }

    private function typeParticipationGroupes(): TypeClassement
    {
        return $this->assezDeSolennels()
            ? TypeClassement::GroupesParticipation
            : TypeClassement::GroupesParticipationTous;
    }

    /**
     * « deux ans de plus que », « autant que » : l'écart d'âge tel que la page
     * le formule, porté de `Functions_datan::int2str()`.
     */
    private function ecartEnMots(int $annees): string
    {
        if ($annees === 0) {
            return 'autant que';
        }

        $mots = [
            1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
            6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix',
            11 => 'onze', 12 => 'douze', 13 => 'treize', 14 => 'quatorze',
            15 => 'quinze', 16 => 'seize', 17 => 'dix-sept', 18 => 'dix-huit',
            19 => 'dix-neuf', 20 => 'vingt',
        ];

        $valeur = abs($annees);

        return sprintf(
            '%s %s de %s que',
            $mots[$valeur] ?? (string) $valeur,
            $valeur === 1 ? 'an' : 'ans',
            $annees > 0 ? 'plus' : 'moins',
        );
    }

    /**
     * Ces pages ne changent qu'au rythme du recalcul nocturne des classements :
     * même politique de cache que les pages de député et de groupe.
     */
    private function cachee(Response $response): Response
    {
        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }
}
