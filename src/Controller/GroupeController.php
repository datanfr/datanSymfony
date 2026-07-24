<?php

namespace App\Controller;

use App\BlocPolitique;
use App\CouleurGroupe;
use App\Entity\Dossier;
use App\Entity\FonctionGroupe;
use App\FamilleGroupe;
use App\Legislature;
use App\NatureVote;
use App\Referencement\OpenGraph;
use App\Twig\DatanExtension;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques des groupes parlementaires, portées depuis le contrôleur
 * Groupes de l'application CodeIgniter d'origine.
 */
class GroupeController extends AbstractController
{
    private const CACHE_TTL = 3600;

    /** Sigle des députés non-inscrits, exclus des comparaisons entre groupes. */
    private const NON_INSCRITS = 'NI';

    /**
     * Position majoritaire favorable. `vote_groupe` a son propre vocabulaire,
     * distinct de {@see \App\Enum\VotePosition} : il note « nv » les non-votants.
     */
    private const POSITION_POUR = 'pour';

    /** Position politique de la majorité présidentielle, telle que l'Assemblée la déclare. */
    private const POSITION_MAJORITAIRE = 'Majoritaire';

    public function __construct(
        private readonly Connection $connection,
        private readonly OpenGraph $openGraph,
        private readonly DatanExtension $datan,
    ) {
    }

    /**
     * Expression SQL de l'indice d'accord (cohésion) d'une ligne de vote_groupe,
     * identique à celle de l'application d'origine.
     */
    private const COHESION_SQL = '(GREATEST(vg.nombre_pours, vg.nombre_contres, vg.nombre_abstentions)
            - 0.5 * ((vg.nombre_pours + vg.nombre_contres + vg.nombre_abstentions)
                     - GREATEST(vg.nombre_pours, vg.nombre_contres, vg.nombre_abstentions)))
           / NULLIF(vg.nombre_pours + vg.nombre_contres + vg.nombre_abstentions, 0)';

    // Le sigle peut contenir un underscore ou des points : UDI_I en 15e législature, S.R.C. en 13e.
    #[Route('/groupes/legislature-{legislature}/{abrev}', name: 'groupe_individual', requirements: ['legislature' => '\d+', 'abrev' => '[a-z0-9._\-\&]+'], methods: ['GET'])]
    public function individual(int $legislature, string $abrev): Response
    {
        $groupe = $this->groupe($legislature, $abrev);
        $groupeId = (int) $groupe['id'];

        $presidences = $this->presidences($groupeId);
        $soutien = $this->soutien($groupeId);
        $composition = $this->composition($groupe);

        // Le graphique comparatif n'a de sens qu'entre groupes contemporains.
        $comparatif = $soutien['votes'] > 0 && $legislature === Legislature::COURANTE
            ? $this->soutienTousGroupes($legislature)
            : [];

        $membres = array_merge($composition['membres'], $composition['apparentes']);

        $response = $this->render('groupe/individual.html.twig', [
            'groupe' => $groupe,
            'chiffres' => $this->chiffres($membres),
            'comportement' => $this->comportement($groupeId),
            'derniers_votes' => $this->derniersVotes($groupeId),
            'membres' => $membres,
            'president' => $presidences[0] ?? null,
            'presidences' => $presidences,
            'historique' => $this->historique($groupe['uid'], $groupeId),
            'soutien' => $soutien,
            'soutien_groupes' => $comparatif,
            'ogp' => $this->openGraph->pourGroupe($groupe, $groupe['date_fin'] === null),
            'fil_ariane' => $this->filAriane($groupe, avecLegislature: true),
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Liste complète des membres du groupe, page à part entière.
     *
     * La page du groupe n'en montre qu'un aperçu ; c'est ici qu'on trouve tout
     * le monde, présidence en tête puis membres et apparentés, comme sur le
     * site d'origine.
     */
    #[Route('/groupes/legislature-{legislature}/{abrev}/membres', name: 'groupe_membres', requirements: ['legislature' => '\d+', 'abrev' => '[a-z0-9._\-\&]+'], methods: ['GET'])]
    public function membres(int $legislature, string $abrev): Response
    {
        $groupe = $this->groupe($legislature, $abrev);
        $composition = $this->composition($groupe);

        $response = $this->render('groupe/membres.html.twig', $composition + [
            'groupe' => $groupe,
            'president' => $this->presidences((int) $groupe['id'])[0] ?? null,
            'fil_ariane' => [...$this->filAriane($groupe, avecLegislature: true),
                ['nom' => 'Membres', 'url' => $this->generateUrl('groupe_membres', $this->parametresRoute($groupe))],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Positions du groupe sur les votes décryptés, filtrables par thématique.
     */
    #[Route('/groupes/legislature-{legislature}/{abrev}/votes', name: 'groupe_votes', requirements: ['legislature' => '\d+', 'abrev' => '[a-z0-9._\-\&]+'], methods: ['GET'])]
    public function votes(int $legislature, string $abrev): Response
    {
        $groupe = $this->groupe($legislature, $abrev);

        $votes = $this->connection->fetchAllAssociative(
            'SELECT d.title, d.legislature, d.vote_numero,
                    c.name AS categorie_name, c.slug AS categorie_slug,
                    l.name AS lecture_name,
                    s.date_scrutin, vg.position_majoritaire AS position
             FROM decryptage d
             JOIN scrutin s ON s.id = d.scrutin_id
             JOIN vote_groupe vg ON vg.scrutin_id = s.id AND vg.groupe_id = :groupe
             LEFT JOIN categorie c ON c.id = d.categorie_id
             LEFT JOIN lecture l ON l.id = d.lecture_id
             WHERE d.state = :published
             ORDER BY s.date_scrutin DESC',
            ['groupe' => (int) $groupe['id'], 'published' => 'published'],
        );

        // Seules les thématiques sur lesquelles ce groupe s'est prononcé : un
        // filtre qui ne trouverait rien n'a rien à faire dans la colonne.
        $categories = [];
        foreach ($votes as $vote) {
            if ($vote['categorie_slug'] !== null) {
                $categories[$vote['categorie_slug']] = $vote['categorie_name'];
            }
        }
        asort($categories);

        $response = $this->render('groupe/votes.html.twig', [
            'groupe' => $groupe,
            'votes' => $votes,
            'categories' => $categories,
            'president' => $this->presidences((int) $groupe['id'])[0] ?? null,
            'fil_ariane' => [...$this->filAriane($groupe, avecLegislature: false),
                ['nom' => 'Votes', 'url' => $this->generateUrl('groupe_votes', $this->parametresRoute($groupe))],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Tous les scrutins auxquels le groupe a participé, avec sa position et sa
     * cohésion — la liste exhaustive, par opposition aux seuls décryptés.
     */
    #[Route('/groupes/legislature-{legislature}/{abrev}/votes/all', name: 'groupe_votes_tous', requirements: ['legislature' => '\d+', 'abrev' => '[a-z0-9._\-\&]+'], methods: ['GET'])]
    public function tousLesVotes(int $legislature, string $abrev): Response
    {
        $groupe = $this->groupe($legislature, $abrev);

        // Le numéro est rendu négatif pour un vote du Congrès, convention que
        // partagent `decryptage.vote_numero` et la fonction Twig `lien_vote` :
        // sans elle, la ligne du Congrès pointerait vers le scrutin de
        // l'Assemblée portant le même numéro. Date et libellé sont mis en forme
        // par la base : sur huit mille lignes, chaque appel de fonction rendu
        // dans Twig se paie huit mille fois.
        $votes = $this->connection->fetchAllAssociative(
            "SELECT s.legislature,
                    CASE WHEN s.uid LIKE 'VTCGR%' THEN -s.numero ELSE s.numero END AS numero,
                    CONCAT(UPPER(LEFT(s.titre, 1)), SUBSTRING(s.titre, 2)) AS titre,
                    DATE_FORMAT(s.date_scrutin, '%d-%m-%Y') AS date_scrutin,
                    vg.position_majoritaire AS position,
                    ROUND(" . self::COHESION_SQL . ", 3) AS cohesion,
                    UPPER(dcr.title) AS decryptage_title
             FROM vote_groupe vg
             JOIN scrutin s ON s.id = vg.scrutin_id
             LEFT JOIN decryptage dcr ON dcr.scrutin_id = s.id AND dcr.state = :published
             WHERE vg.groupe_id = :groupe
             ORDER BY s.date_scrutin DESC, s.numero DESC",
            ['groupe' => (int) $groupe['id'], 'published' => 'published'],
        );

        $response = $this->render('groupe/votes_tous.html.twig', [
            'groupe' => $groupe,
            'votes' => $votes,
            'president' => $this->presidences((int) $groupe['id'])[0] ?? null,
            'fil_ariane' => [...$this->filAriane($groupe, avecLegislature: false),
                ['nom' => 'Votes', 'url' => $this->generateUrl('groupe_votes', $this->parametresRoute($groupe))],
                ['nom' => 'Tous les votes', 'url' => $this->generateUrl('groupe_votes_tous', $this->parametresRoute($groupe))],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Statistiques détaillées du groupe : cohésion, participation, et proximité
     * avec chacun des autres groupes de sa législature.
     *
     * Les ancres `#cohesion`, `#participation` et `#majorite` sont celles vers
     * lesquelles pointe la page du groupe.
     */
    #[Route('/groupes/legislature-{legislature}/{abrev}/statistiques', name: 'groupe_statistiques', requirements: ['legislature' => '\d+', 'abrev' => '[a-z0-9._\-\&]+'], methods: ['GET'])]
    public function statistiques(int $legislature, string $abrev): Response
    {
        $groupe = $this->groupe($legislature, $abrev);
        $groupeId = (int) $groupe['id'];

        $proximites = $this->proximites($groupeId);

        // La majorité présidentielle sert de point de comparaison. Elle n'existe
        // pas toujours : depuis la dissolution de 2024, l'Assemblée ne déclare
        // plus aucun groupe « Majoritaire », et le bloc disparaît alors — comme
        // sur le site d'origine, qui lit la même donnée.
        $majorite = null;
        foreach ($proximites as $ligne) {
            if ($ligne['position_politique'] === self::POSITION_MAJORITAIRE) {
                $majorite = $ligne;
                break;
            }
        }

        $coalitions = $this->coalitions($groupeId, $legislature);

        $response = $this->render('groupe/statistiques.html.twig', [
            'groupe' => $groupe,
            'comportement' => $this->comportement($groupeId),
            'chiffres' => $this->chiffres(array_merge(...array_values($this->composition($groupe)))),
            'proximites' => $proximites,
            'coalitions' => $coalitions,
            'coalitions_couleurs' => $this->couleursParSigle($legislature),
            'proximite_mensuelle' => $this->proximiteParMois($groupeId),
            // La lecture en blocs politiques n'est établie que pour la 17e
            // législature : ailleurs, on montre les coalitions sans les
            // commenter plutôt que d'inventer un partage.
            'coalition_blocs' => $legislature === BlocPolitique::LEGISLATURE && $coalitions !== []
                ? BlocPolitique::repartis($coalitions[0]['sigles'])
                : [],
            'majorite' => $majorite,
            'plus_proche' => $proximites[0] ?? null,
            'plus_eloigne' => $proximites !== [] ? end($proximites) : null,
            'president' => $this->presidences($groupeId)[0] ?? null,
            'fil_ariane' => [...$this->filAriane($groupe, avecLegislature: false),
                ['nom' => 'Statistiques', 'url' => $this->generateUrl('groupe_statistiques', $this->parametresRoute($groupe))],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Évolution mois par mois de la proximité du groupe avec chacun des autres.
     *
     * Même règle d'accord que {@see proximites()}, resserrée sur le mois du
     * scrutin : c'est la lecture qui montre les recompositions, un groupe
     * pouvant s'éloigner d'un autre au fil d'une législature sans que la
     * moyenne d'ensemble le laisse voir. Les non-inscrits en sont écartés,
     * comme des coalitions.
     *
     * @return array{mois: list<string>, series: list<array{groupe: string, couleur: string, scores: list<int|null>}>}
     */
    private function proximiteParMois(int $groupeId): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            "SELECT DATE_FORMAT(s.date_scrutin, '%Y-%m') AS mois,
                    g.libelle_abrev,
                    " . CouleurGroupe::SQL . " AS couleur,
                    ROUND(AVG(nous.position_majoritaire = autre.position_majoritaire
                              AND nous.position_majoritaire IN ('pour', 'contre')) * 100) AS score
             FROM vote_groupe nous
             JOIN vote_groupe autre ON autre.scrutin_id = nous.scrutin_id AND autre.groupe_id <> nous.groupe_id
             JOIN groupe g ON g.id = autre.groupe_id
             JOIN scrutin s ON s.id = nous.scrutin_id
             WHERE nous.groupe_id = :groupe AND g.libelle_abrev <> :non_inscrits
             GROUP BY mois, g.id
             ORDER BY mois",
            ['groupe' => $groupeId, 'non_inscrits' => self::NON_INSCRITS],
        );

        if ($lignes === []) {
            return ['mois' => [], 'series' => []];
        }

        $mois = array_values(array_unique(array_column($lignes, 'mois')));
        $rangs = array_flip($mois);

        // Une courbe par groupe, trouée aux mois où il n'existait pas encore ou
        // n'a rien voté : Chart.js interrompt le trait plutôt que de le faire
        // plonger à zéro, ce qui serait un contresens.
        $series = [];
        foreach ($lignes as $ligne) {
            $sigle = (string) $ligne['libelle_abrev'];
            $series[$sigle] ??= [
                'groupe' => $sigle,
                'couleur' => (string) ($ligne['couleur'] ?? '#6c757d'),
                'scores' => array_fill(0, \count($mois), null),
            ];
            $series[$sigle]['scores'][$rangs[$ligne['mois']]] = (int) $ligne['score'];
        }

        return ['mois' => $mois, 'series' => array_values($series)];
    }

    /**
     * Couleur d'affichage de chaque groupe d'une législature, par sigle : les
     * coalitions ne manipulent que des sigles.
     *
     * @return array<string, string>
     */
    private function couleursParSigle(int $legislature): array
    {
        return $this->connection->fetchAllKeyValue(
            'SELECT g.libelle_abrev, ' . CouleurGroupe::SQL . '
             FROM groupe g WHERE g.legislature = :legislature',
            ['legislature' => $legislature],
        );
    }

    /**
     * Les coalitions dans lesquelles le groupe s'est le plus souvent trouvé.
     *
     * Une coalition, c'est l'ensemble des groupes ayant pris la même position
     * majoritaire sur un scrutin — « pour » ou « contre », l'abstention n'en
     * formant pas une. Les non-inscrits en sont exclus : ils ne constituent pas
     * un groupe et ne s'allient pas. Le calcul se fait à la volée plutôt que
     * dans une table précalculée, la signature d'un camp se construisant en un
     * `GROUP_CONCAT`.
     *
     * @return list<array{sigles: list<string>, votes: int}>
     */
    private function coalitions(int $groupeId, int $legislature): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            "SELECT camps.coalition, COUNT(*) AS votes
             FROM (
                 SELECT vg.scrutin_id,
                        GROUP_CONCAT(g.libelle_abrev ORDER BY g.libelle_abrev SEPARATOR '|') AS coalition,
                        SUM(vg.groupe_id = :groupe) AS nous
                 FROM vote_groupe vg
                 JOIN groupe g ON g.id = vg.groupe_id
                 WHERE g.legislature = :legislature
                   AND vg.position_majoritaire IN ('pour', 'contre')
                   AND g.libelle_abrev <> :non_inscrits
                 GROUP BY vg.scrutin_id, vg.position_majoritaire
             ) camps
             WHERE camps.nous = 1
             GROUP BY camps.coalition
             ORDER BY votes DESC
             LIMIT 8",
            ['groupe' => $groupeId, 'legislature' => $legislature, 'non_inscrits' => self::NON_INSCRITS],
        );

        return array_map(
            static fn (array $ligne) => [
                'sigles' => explode('|', (string) $ligne['coalition']),
                'votes' => (int) $ligne['votes'],
            ],
            $lignes,
        );
    }

    /**
     * Taux de proximité du groupe avec chacun des autres, du plus proche au
     * plus éloigné.
     *
     * Deux groupes sont d'accord sur un scrutin **quand ils ont voté pour tous
     * les deux, ou contre tous les deux** — deux abstentions ne valent pas
     * accord, et une abstention face à un vote pour ne vaut pas désaccord
     * partiel : c'est 0 ou 1 (`daily.php:2176`). La règle est contre-intuitive
     * mais c'est celle qu'affiche le site depuis toujours.
     *
     * @return list<array<string, mixed>>
     */
    private function proximites(int $groupeId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT g.id, g.libelle, g.libelle_abrev, g.legislature, g.position_politique,
                    g.date_fin IS NOT NULL AS dissous,
                    " . CouleurGroupe::SQL . " AS couleur,
                    COUNT(*) AS votes,
                    ROUND(AVG(nous.position_majoritaire = autre.position_majoritaire
                              AND nous.position_majoritaire IN ('pour', 'contre')) * 100) AS score
             FROM vote_groupe nous
             JOIN vote_groupe autre ON autre.scrutin_id = nous.scrutin_id AND autre.groupe_id <> nous.groupe_id
             JOIN groupe g ON g.id = autre.groupe_id
             WHERE nous.groupe_id = :groupe
             GROUP BY g.id
             ORDER BY score DESC, votes DESC",
            ['groupe' => $groupeId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Paramètres de route communs aux cinq pages du groupe.
     *
     * @param array<string, mixed> $groupe
     *
     * @return array{legislature: int, abrev: string}
     */
    private function parametresRoute(array $groupe): array
    {
        return [
            'legislature' => (int) $groupe['legislature'],
            'abrev' => mb_strtolower((string) $groupe['libelle_abrev']),
        ];
    }

    /**
     * Tronc commun du fil d'Ariane : Datan, Groupes, puis le groupe. L'origine
     * n'intercale la législature — « Législature 16 » — que sur la fiche et la
     * page des membres, jamais sur les votes ni les statistiques, et seulement
     * hors législature courante.
     *
     * @param array<string, mixed> $groupe
     *
     * @return list<array{nom: string, url: string}>
     */
    private function filAriane(array $groupe, bool $avecLegislature): array
    {
        $legislature = (int) $groupe['legislature'];

        $fil = [
            ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
            ['nom' => 'Groupes', 'url' => $this->generateUrl('groupes_index')],
        ];

        if ($avecLegislature && $legislature !== Legislature::COURANTE) {
            $fil[] = ['nom' => 'Législature ' . $legislature, 'url' => $this->generateUrl('groupes_legislature', ['legislature' => $legislature])];
        }

        $fil[] = ['nom' => $this->datan->nameGroup($groupe['libelle']), 'url' => $this->generateUrl('groupe_individual', $this->parametresRoute($groupe))];

        return $fil;
    }

    private function groupe(int $legislature, string $abrev): array
    {
        $groupe = $this->connection->fetchAssociative(
            'SELECT id, uid, libelle, libelle_abrev, libelle_abrege, couleur, position_politique,
                    legislature, date_debut, date_fin
             FROM groupe
             WHERE legislature = :legislature AND LOWER(libelle_abrev) = :abrev
             LIMIT 1',
            ['legislature' => $legislature, 'abrev' => mb_strtolower($abrev)],
        );

        if ($groupe === false) {
            throw $this->createNotFoundException('Groupe introuvable.');
        }

        return $groupe;
    }

    /**
     * Effectif, âge moyen et taux de féminisation du groupe.
     *
     * Comptés sur la composition déjà chargée plutôt que par une requête sur
     * `depute.groupe_id` : celle-ci renverrait zéro pour tout groupe dissous.
     * Les apparentés comptent dans l'effectif, comme à l'Assemblée.
     *
     * @param list<array<string, mixed>> $composition
     *
     * @return array<string, int|null>
     */
    private function chiffres(array $composition): array
    {
        $ages = array_filter(array_column($composition, 'age'), static fn ($age) => $age !== null);
        $civilites = array_filter(array_column($composition, 'civilite'), static fn ($c) => $c !== null);
        $femmes = \count(array_filter($civilites, static fn (string $c) => $c === 'Mme'));

        return [
            'effectif' => \count($composition),
            'age_moyen' => $ages !== [] ? (int) round(array_sum($ages) / \count($ages)) : null,
            'femmes' => $femmes,
            'feminisation' => $civilites !== [] ? (int) round($femmes / \count($civilites) * 100) : null,
        ];
    }

    /**
     * Comportement du groupe : cohésion moyenne et participation moyenne,
     * agrégées sur toutes ses ventilations de scrutin.
     *
     * @return array<string, float|int|null>
     */
    private function comportement(int $groupeId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS scrutins,
                    ROUND(AVG(' . self::COHESION_SQL . '), 3) AS cohesion_moyenne,
                    ROUND(AVG((vg.nombre_pours + vg.nombre_contres + vg.nombre_abstentions)
                              / NULLIF(vg.nombre_membres_groupe, 0)) * 100) AS participation_moyenne
             FROM vote_groupe vg
             WHERE vg.groupe_id = :groupe',
            ['groupe' => $groupeId],
        ) ?: [];

        return [
            'scrutins' => (int) ($row['scrutins'] ?? 0),
            'cohesion_moyenne' => $row['cohesion_moyenne'] !== null ? (float) $row['cohesion_moyenne'] : null,
            'participation_moyenne' => $row['participation_moyenne'] !== null ? (int) $row['participation_moyenne'] : null,
        ];
    }

    /**
     * Derniers votes décryptés, avec la position prise par le groupe.
     *
     * @return list<array<string, mixed>>
     */
    private function derniersVotes(int $groupeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT dcr.title, dcr.legislature, dcr.vote_numero, c.name AS categorie_name,
                    s.sort_code, s.date_scrutin,
                    vg.position_majoritaire, vg.nombre_pours, vg.nombre_contres, vg.nombre_abstentions,
                    ROUND(' . self::COHESION_SQL . ', 3) AS cohesion
             FROM decryptage dcr
             JOIN scrutin s ON s.id = dcr.scrutin_id
             JOIN vote_groupe vg ON vg.scrutin_id = s.id AND vg.groupe_id = :groupe
             LEFT JOIN categorie c ON c.id = dcr.categorie_id
             WHERE dcr.state = :published
             ORDER BY s.date_scrutin DESC
             LIMIT 6',
            ['groupe' => $groupeId, 'published' => 'published'],
        );
    }

    /**
     * Présidences successives du groupe, la plus récente en tête.
     * Celle qui n'a pas de date de fin est la présidence en cours.
     *
     * @return list<array<string, mixed>>
     */
    private function presidences(int $groupeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.firstname, d.lastname, d.slug, d.dpt_slug, d.civilite,
                    f.date_debut, f.date_fin
             FROM fonction_groupe f
             JOIN depute d ON d.id = f.depute_id
             WHERE f.groupe_id = :groupe AND f.code_qualite = :qualite
             ORDER BY f.date_fin IS NOT NULL, f.date_debut DESC',
            ['groupe' => $groupeId, 'qualite' => FonctionGroupe::QUALITE_PRESIDENT],
        );
    }

    /**
     * Les incarnations successives du groupe, de la plus ancienne à la plus
     * récente, telles que déclarées par {@see FamilleGroupe} : un groupe change
     * de nom d'une législature à l'autre, voire au sein d'une même législature.
     *
     * L'effectif est compté sur les rattachements historiques
     * ({@see \App\Entity\FonctionGroupe}) et non sur `depute.groupe_id`, qui ne
     * reflète que la situation courante.
     *
     * @return list<array<string, mixed>>
     */
    private function historique(string $uid, int $groupeIdCourant): array
    {
        $famille = FamilleGroupe::pour($uid);
        if (\count($famille) === 1) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT g.id, g.legislature, g.libelle, g.libelle_abrev, g.couleur,
                    g.date_debut, g.date_fin,
                    COUNT(DISTINCT f.depute_id) AS effectif
             FROM groupe g
             LEFT JOIN fonction_groupe f ON f.groupe_id = g.id
             WHERE g.uid IN (:famille) AND g.id <> :courant AND g.legislature >= :premiere
             GROUP BY g.id, g.legislature, g.libelle, g.libelle_abrev, g.couleur, g.date_debut, g.date_fin
             ORDER BY g.date_debut',
            ['famille' => $famille, 'courant' => $groupeIdCourant, 'premiere' => Legislature::PREMIERE],
            ['famille' => ArrayParameterType::STRING],
        );
    }

    /**
     * Taux de soutien au gouvernement : la part des textes présentés par le
     * Gouvernement que le groupe a votés.
     *
     * Ne comptent que les scrutins qui adoptent ou rejettent un texte dans son
     * ensemble ({@see NatureVote::FINALE}) et qui portent sur un projet de loi
     * ({@see Dossier::PROCEDURES_GOUVERNEMENT}) : les votes sur des amendements
     * ou des articles ne disent rien du soutien au texte lui-même.
     *
     * @return array{votes: int, soutiens: int, pourcentage: int|null}
     */
    private function soutien(int $groupeId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS votes, SUM(vg.position_majoritaire = :pour) AS soutiens
             FROM scrutin s
             JOIN dossier d ON d.id = s.dossier_id
             JOIN vote_groupe vg ON vg.scrutin_id = s.id AND vg.groupe_id = :groupe
             WHERE s.nature_vote = :finale AND d.procedure_code IN (:procedures)',
            [
                'groupe' => $groupeId,
                'pour' => self::POSITION_POUR,
                'finale' => NatureVote::FINALE,
                'procedures' => Dossier::PROCEDURES_GOUVERNEMENT,
            ],
            ['procedures' => ArrayParameterType::INTEGER],
        ) ?: [];

        $votes = (int) ($row['votes'] ?? 0);
        $soutiens = (int) ($row['soutiens'] ?? 0);

        return [
            'votes' => $votes,
            'soutiens' => $soutiens,
            'pourcentage' => $votes > 0 ? (int) round($soutiens / $votes * 100) : null,
        ];
    }

    /**
     * Le même décompte pour tous les groupes de la législature, qui alimente le
     * graphique comparatif. Les non-inscrits en sont exclus : ils ne forment pas
     * un groupe et leur position majoritaire n'engage personne.
     *
     * Les groupes d'une même famille sont fusionnés sous celui qui est encore en
     * exercice, faute de quoi une scission ferait disparaître les votes du
     * groupe dissous.
     *
     * @return list<array<string, mixed>>
     */
    private function soutienTousGroupes(int $legislature): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT g.id, g.uid, g.libelle, g.libelle_abrev, g.couleur, g.date_fin,
                    COUNT(*) AS votes,
                    SUM(vg.position_majoritaire = :pour) AS soutiens,
                    (SELECT COUNT(*) FROM depute dep WHERE dep.groupe_id = g.id) AS effectif
             FROM groupe g
             JOIN vote_groupe vg ON vg.groupe_id = g.id
             JOIN scrutin s ON s.id = vg.scrutin_id
             JOIN dossier d ON d.id = s.dossier_id
             WHERE g.legislature = :legislature AND g.libelle_abrev <> :ni
               AND s.nature_vote = :finale AND d.procedure_code IN (:procedures)
             GROUP BY g.id, g.uid, g.libelle, g.libelle_abrev, g.couleur, g.date_fin',
            [
                'legislature' => $legislature,
                'ni' => self::NON_INSCRITS,
                'pour' => self::POSITION_POUR,
                'finale' => NatureVote::FINALE,
                'procedures' => Dossier::PROCEDURES_GOUVERNEMENT,
            ],
            ['procedures' => ArrayParameterType::INTEGER],
        );

        foreach ($lignes as $i => $ligne) {
            foreach (['votes', 'soutiens', 'effectif'] as $compteur) {
                $lignes[$i][$compteur] = (int) $ligne[$compteur];
            }
        }

        $parUid = array_column($lignes, null, 'uid');

        foreach ($lignes as $ligne) {
            $famille = array_intersect(FamilleGroupe::pour($ligne['uid']), array_keys($parUid));
            if (\count($famille) < 2) {
                continue;
            }

            // Le représentant est le groupe encore en exercice ; à défaut, le premier trouvé.
            $representant = null;
            foreach ($famille as $uid) {
                if ($parUid[$uid]['date_fin'] === null) {
                    $representant = $uid;
                    break;
                }
            }
            $representant ??= reset($famille);

            foreach ($famille as $uid) {
                if ($uid !== $representant) {
                    $parUid[$representant]['soutiens'] += $parUid[$uid]['soutiens'];
                    $parUid[$representant]['votes'] += $parUid[$uid]['votes'];
                    unset($parUid[$uid]);
                }
            }
        }

        $groupes = array_values($parUid);
        usort($groupes, static fn (array $a, array $b) => [$b['soutiens'], $b['effectif']] <=> [$a['soutiens'], $a['effectif']]);

        return $groupes;
    }

    /**
     * Les députés rattachés au groupe, séparés en membres de plein droit et
     * apparentés.
     *
     * La composition ne se lit pas sur `depute.groupe_id`, qui ne porte que la
     * situation du jour : la page d'un groupe dissous serait vide. Elle se lit
     * sur `fonction_groupe`, aux dates d'existence du groupe — pour un groupe
     * clos, l'appartenance retenue est celle du jour de sa disparition, comme
     * dans l'application d'origine (`Groupes_model::get_groupe_membres()`).
     *
     * `nomin_principale` n'est pas une précaution de style : onze députés de la
     * 17e législature portent un second rattachement ouvert, et sans ce filtre
     * ils apparaîtraient dans deux groupes à la fois.
     *
     * @param array<string, mixed> $groupe
     *
     * @return array{membres: list<array<string, mixed>>, apparentes: list<array<string, mixed>>}
     */
    private function composition(array $groupe): array
    {
        $clos = $groupe['date_fin'] !== null;

        $lignes = $this->connection->fetchAllAssociative(
            'SELECT d.mp_id, d.firstname, d.lastname, d.slug, d.dpt_slug, d.civilite, d.age,
                    d.departement_nom, d.departement_code, d.circonscription,
                    appartenance.code_qualite,
                    g.libelle AS groupe_libelle, g.libelle_abrev AS groupe_abrev,
                    ' . CouleurGroupe::SQL . ' AS groupe_couleur,
                    dl.legislature_last
             FROM (
                 SELECT fg.depute_id, fg.code_qualite,
                        ROW_NUMBER() OVER (PARTITION BY fg.depute_id ORDER BY fg.date_debut DESC) AS rang
                 FROM fonction_groupe fg
                 WHERE fg.groupe_id = :groupe AND fg.nomin_principale = 1
                   AND ' . ($clos ? ':fin BETWEEN fg.date_debut AND fg.date_fin' : 'fg.date_fin IS NULL') . '
             ) appartenance
             JOIN depute d ON d.id = appartenance.depute_id
             JOIN groupe g ON g.id = :groupe
             LEFT JOIN (SELECT depute_id, MAX(legislature) AS legislature_last
                        FROM mandat GROUP BY depute_id) dl ON dl.depute_id = d.id
             WHERE appartenance.rang = 1
             ORDER BY d.lastname, d.firstname',
            ['groupe' => (int) $groupe['id']] + ($clos ? ['fin' => $groupe['date_fin']] : []),
        );

        $membres = [];
        $apparentes = [];

        foreach ($lignes as $ligne) {
            if ($ligne['code_qualite'] === FonctionGroupe::QUALITE_APPARENTE) {
                $apparentes[] = $ligne;
            } else {
                $membres[] = $ligne;
            }
        }

        return ['membres' => $membres, 'apparentes' => $apparentes];
    }
}
