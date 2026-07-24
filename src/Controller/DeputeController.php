<?php

namespace App\Controller;

use App\Legislature;
use App\Referencement\OpenGraph;
use App\Repository\ResultatCirconscriptionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques des députés, portées depuis le contrôleur Deputes de
 * l'application CodeIgniter d'origine.
 */
class DeputeController extends AbstractController
{
    private const OFFICIAL = 'decompteNominatif';

    /** Code des scrutins publics solennels, sur lesquels se calcule la participation. */
    private const SOLENNEL = 'SPS';

    /** Les données d'un député ne bougent qu'au rythme des scrutins : cache d'une heure. */
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly ResultatCirconscriptionRepository $resultatsCirconscription,
        private readonly OpenGraph $openGraph,
    ) {
    }

    /**
     * L'URL reprend celle de l'application d'origine (/deputes/nord-59/depute_ugo-bernalicis)
     * pour ne pas casser les liens existants ni le référencement.
     */
    #[Route('/deputes/{dptSlug}/depute_{slug}', name: 'depute_individual', requirements: ['dptSlug' => '[a-z0-9\-]+', 'slug' => '[a-z0-9\-]+'], methods: ['GET'])]
    public function individual(string $dptSlug, string $slug): Response
    {
        // Un slug n'est pas unique : le député Jean-Louis Masson (Var) partage
        // le sien avec un sénateur homonyme du dépôt d'acteurs, sans page. À
        // slug égal, la ligne qui a un dpt_slug — donc une fiche — gagne, sans
        // quoi le LIMIT 1 peut tirer l'homonyme et rendre un 404 que le plan
        // des députés annonce pourtant en 200. Même parade dans depute().
        $depute = $this->connection->fetchAssociative(
            'SELECT d.id, d.mp_id, d.firstname, d.lastname, d.slug, d.dpt_slug, d.civilite, d.age,
                    d.date_fin, d.cause_fin, d.departement_nom, d.departement_code,
                    d.circonscription, d.region, d.place_hemicycle, d.profession, d.commission,
                    g.id AS groupe_id, g.libelle AS groupe_libelle, g.libelle_abrev AS groupe_abrev,
                    g.couleur AS groupe_couleur, g.position_politique,
                    dep.libelle_de
             FROM depute d
             LEFT JOIN groupe g ON g.id = d.groupe_id
             LEFT JOIN departement dep ON dep.code = d.departement_code
             WHERE d.slug = :slug
             ORDER BY (d.dpt_slug IS NULL), d.id
             LIMIT 1',
            ['slug' => $slug],
        );

        if ($depute === false) {
            throw $this->createNotFoundException('Député introuvable.');
        }

        // Sans dpt_slug, pas de page : ces lignes viennent du dépôt d'acteurs des
        // Tricoteuses, qui couvre plus que l'Assemblée — sénateurs, membres du
        // gouvernement — et aucune n'a de mandat de député chez nous. L'application
        // d'origine ne publie que les acteurs de sa table `deputes_last` : servir
        // ceux-ci, à n'importe quelle adresse de département faute de forme
        // canonique, fabriquerait du contenu dupliqué qu'elle n'a jamais eu.
        if ($depute['dpt_slug'] === null) {
            throw $this->createNotFoundException('Acteur sans mandat de député : pas de fiche publiée.');
        }

        // URL canonique : on redirige si le département de l'URL n'est pas le bon.
        if ($depute['dpt_slug'] !== $dptSlug) {
            return $this->redirectToRoute('depute_individual', [
                'dptSlug' => $depute['dpt_slug'],
                'slug' => $depute['slug'],
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $deputeId = (int) $depute['id'];
        $groupeId = $depute['groupe_id'] !== null ? (int) $depute['groupe_id'] : null;

        $mandats = $this->mandats($deputeId);

        // Le statut se déduit des mandats, pas de depute.date_fin : cette colonne
        // vient de la migration initiale et n'est pas rafraîchie lors des réélections.
        // Un mandat sans date de fin signifie que le député est en exercice.
        $actif = $mandats !== [] && $mandats[0]['date_fin'] === null;

        // Bloc « Son élection » : le résultat de la circonscription pour le mandat
        // le plus récent — sa législature en fixe l'année, son département et sa
        // circonscription la clé. Le nom lève l'ambiguïté quand la circonscription
        // a connu une élection partielle.
        $election = $mandats !== []
            ? $this->resultatsCirconscription->pourDepute(
                $mandats[0]['departement_code'],
                $mandats[0]['circonscription'] !== null ? (int) $mandats[0]['circonscription'] : null,
                (int) $mandats[0]['legislature'],
                (string) $depute['lastname'],
            )
            : null;

        $response = $this->render('depute/individual.html.twig', [
            'depute' => $depute,
            'actif' => $actif,
            'participation' => $this->participation($deputeId),
            'loyaute' => $groupeId !== null ? $this->loyaute($deputeId, $groupeId) : null,
            'derniers_votes' => $this->derniersVotes($deputeId),
            'positions_cles' => $this->positionsCles($deputeId),
            'mandats' => $mandats,
            'election' => $election,
            'ogp' => $this->openGraph->pourDepute($depute, $actif),
            'fil_ariane' => $this->filAriane($depute),
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Tous les votes d'un député : les décryptés en cartes, puis l'intégralité
     * des scrutins où il s'est exprimé.
     */
    #[Route('/deputes/{dptSlug}/depute_{slug}/votes', name: 'depute_votes', requirements: ['dptSlug' => '[a-z0-9\-]+', 'slug' => '[a-z0-9\-]+'], methods: ['GET'])]
    public function votes(string $dptSlug, string $slug): Response
    {
        $depute = $this->depute($slug);

        if ($depute['dpt_slug'] !== $dptSlug) {
            return $this->redirectToRoute('depute_votes', [
                'dptSlug' => $depute['dpt_slug'],
                'slug' => $depute['slug'],
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $deputeId = (int) $depute['id'];
        $decryptes = $this->votesDecryptes($deputeId);

        $categories = [];
        foreach ($decryptes as $vote) {
            if ($vote['categorie_slug'] !== null) {
                $categories[$vote['categorie_slug']] = $vote['categorie_name'];
            }
        }
        asort($categories);

        $mandats = $this->mandats($deputeId);

        $response = $this->render('depute/votes.html.twig', [
            'depute' => $depute,
            'actif' => $mandats !== [] && $mandats[0]['date_fin'] === null,
            'decryptes' => $decryptes,
            'categories' => $categories,
            'tous_les_votes' => $this->tousLesVotes($deputeId, $depute['groupe_id'] !== null ? (int) $depute['groupe_id'] : null),
            // Pas de carte dédiée ici : le contrôleur d'origine ne compose le
            // visuel de profil que pour la fiche et l'historique, la page des
            // votes garde la carte générique.
            'fil_ariane' => [...$this->filAriane($depute),
                ['nom' => 'Votes', 'url' => $this->generateUrl('depute_votes', ['dptSlug' => $depute['dpt_slug'], 'slug' => $depute['slug']])],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Le député tel qu'il siégeait à une législature passée.
     *
     * Sa circonscription, son département et son groupe ont pu changer d'une
     * législature à l'autre : c'est le mandat de l'époque qui fait foi, pas la
     * fiche d'aujourd'hui. L'adresse de la dernière législature redirige vers la
     * fiche principale, qui dit déjà la même chose.
     */
    #[Route('/deputes/{dptSlug}/depute_{slug}/legislature-{legislature}', name: 'depute_legislature', requirements: ['dptSlug' => '[a-z0-9\-]+', 'slug' => '[a-z0-9\-]+', 'legislature' => '\d+'], methods: ['GET'])]
    public function legislature(string $dptSlug, string $slug, int $legislature): Response
    {
        // Le site ne publie rien avant la 14e : en deçà, l'application d'origine
        // répond 404 même si nos mandats remontent plus loin.
        if ($legislature < Legislature::PREMIERE) {
            throw $this->createNotFoundException('Législature inconnue.');
        }

        $depute = $this->depute($slug);
        $deputeId = (int) $depute['id'];
        $mandats = $this->mandats($deputeId);

        $mandat = null;
        foreach ($mandats as $ligne) {
            if ((int) $ligne['legislature'] === $legislature) {
                $mandat = $ligne;
                break;
            }
        }

        if ($mandat === null) {
            throw $this->createNotFoundException(sprintf('Ce député n\'a pas siégé en %de législature.', $legislature));
        }

        if ($mandats !== [] && (int) $mandats[0]['legislature'] === $legislature) {
            return $this->redirectToRoute('depute_individual', [
                'dptSlug' => $depute['dpt_slug'],
                'slug' => $depute['slug'],
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $groupe = $this->groupeALegislature($deputeId, $legislature);

        $response = $this->render('depute/legislature.html.twig', [
            'depute' => $depute,
            'mandat' => $mandat,
            'mandats' => $mandats,
            'legislature' => $legislature,
            'groupe' => $groupe,
            'participation' => $this->participation($deputeId, $legislature),
            'loyaute' => $groupe !== null ? $this->loyaute($deputeId, (int) $groupe['id'], $legislature) : null,
            'derniers_votes' => $this->derniersVotes($deputeId, $legislature),
            // La carte porte le groupe de l'époque, celui que la page affiche —
            // l'origine y met le dernier groupe connu, mais `depute.groupe_id`
            // ne porte que l'appartenance courante, vide pour un ancien député.
            'ogp' => $this->openGraph->pourDepute(
                ['groupe_libelle' => $groupe['libelle'] ?? null, 'groupe_couleur' => $groupe['couleur'] ?? null] + $depute,
                $mandats !== [] && $mandats[0]['date_fin'] === null,
            ),
            // « législature » : l'origine affiche « Historique 16e legislature »,
            // sans accent — coquille, pas choix.
            'fil_ariane' => [...$this->filAriane($depute),
                ['nom' => \sprintf('Historique %de législature', $legislature), 'url' => $this->generateUrl('depute_legislature', ['dptSlug' => $depute['dpt_slug'], 'slug' => $depute['slug'], 'legislature' => $legislature])],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Le groupe du député pendant une législature donnée, lu sur
     * `fonction_groupe` : `depute.groupe_id` ne porte que l'appartenance
     * courante et donnerait le groupe d'aujourd'hui, voire aucun.
     *
     * @return array<string, mixed>|null
     */
    private function groupeALegislature(int $deputeId, int $legislature): ?array
    {
        $groupe = $this->connection->fetchAssociative(
            'SELECT g.id, g.libelle, g.libelle_abrev, g.couleur, g.legislature
             FROM fonction_groupe fg
             JOIN groupe g ON g.id = fg.groupe_id
             WHERE fg.depute_id = :depute AND g.legislature = :legislature AND fg.nomin_principale = 1
             ORDER BY fg.date_fin IS NULL DESC, fg.date_fin DESC, fg.date_debut DESC
             LIMIT 1',
            ['depute' => $deputeId, 'legislature' => $legislature],
        );

        return $groupe === false ? null : $groupe;
    }

    /**
     * Tronc commun du fil d'Ariane des pages d'un député : Datan, Députés, le
     * département, puis l'initiale et le nom (« U. Bernalicis »), comme le
     * `title_breadcrumb` de l'origine. Les sous-pages y accolent leur maillon.
     *
     * @param array<string, mixed> $depute
     *
     * @return list<array{nom: string, url: string}>
     */
    private function filAriane(array $depute): array
    {
        return [
            ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
            ['nom' => 'Députés', 'url' => $this->generateUrl('deputes_index')],
            [
                'nom' => $depute['departement_nom'] . ' (' . $depute['departement_code'] . ')',
                'url' => $this->generateUrl('departement_individual', ['departement' => $depute['dpt_slug']]),
            ],
            [
                'nom' => mb_substr((string) $depute['firstname'], 0, 1) . '. ' . $depute['lastname'],
                'url' => $this->generateUrl('depute_individual', ['dptSlug' => $depute['dpt_slug'], 'slug' => $depute['slug']]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function depute(string $slug): array
    {
        $depute = $this->connection->fetchAssociative(
            'SELECT d.id, d.mp_id, d.firstname, d.lastname, d.slug, d.dpt_slug, d.civilite,
                    d.departement_nom, d.departement_code, d.circonscription,
                    g.id AS groupe_id, g.libelle AS groupe_libelle, g.libelle_abrev AS groupe_abrev,
                    g.couleur AS groupe_couleur, g.legislature AS groupe_legislature,
                    dep.libelle_de
             FROM depute d
             LEFT JOIN groupe g ON g.id = d.groupe_id
             LEFT JOIN departement dep ON dep.code = d.departement_code
             WHERE d.slug = :slug
             ORDER BY (d.dpt_slug IS NULL), d.id
             LIMIT 1',
            ['slug' => $slug],
        );

        if ($depute === false) {
            throw $this->createNotFoundException('Député introuvable.');
        }

        // Même garde que sur la fiche principale : un acteur sans dpt_slug n'a
        // jamais siégé comme député et n'a pas de page — voir individual().
        if ($depute['dpt_slug'] === null) {
            throw $this->createNotFoundException('Acteur sans mandat de député : pas de fiche publiée.');
        }

        return $depute;
    }

    /**
     * Votes décryptés sur lesquels le député s'est prononcé, avec son
     * explication de vote quand il en a publié une.
     *
     * Un scrutin où il n'a pas de ligne `vote` n'apparaît pas : l'application
     * d'origine écarte aussi les non-votants (`vs.vote != 'nv'`), une absence
     * n'étant pas une position.
     *
     * @return list<array<string, mixed>>
     */
    private function votesDecryptes(int $deputeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT dcr.title, dcr.legislature, dcr.vote_numero,
                    c.name AS categorie_name, c.slug AS categorie_slug,
                    l.name AS lecture_name,
                    s.date_scrutin, v.position,
                    e.texte AS explication
             FROM decryptage dcr
             JOIN scrutin s ON s.id = dcr.scrutin_id
             JOIN vote v ON v.scrutin_id = s.id AND v.depute_id = :depute
             LEFT JOIN categorie c ON c.id = dcr.categorie_id
             LEFT JOIN lecture l ON l.id = dcr.lecture_id
             LEFT JOIN explication e ON e.scrutin_id = s.id AND e.depute_id = :depute AND e.publiee = 1
             WHERE dcr.state = :published AND v.position IN (\'pour\', \'contre\', \'abstention\')
             ORDER BY s.date_scrutin DESC',
            ['depute' => $deputeId, 'published' => 'published'],
        );
    }

    /**
     * L'intégralité des scrutins où le député s'est exprimé, avec sa loyauté
     * envers son groupe.
     *
     * Loyauté et position sont confrontées à la position majoritaire du groupe
     * **au moment du scrutin** (`vote_groupe`), pas à celle de son groupe
     * actuel : un député qui change de groupe ne devient pas rétroactivement
     * rebelle. Mise en forme faite par la base — la table compte des milliers
     * de lignes, et chaque filtre Twig s'y paierait autant de fois.
     *
     * @return list<array<string, mixed>>
     */
    private function tousLesVotes(int $deputeId, ?int $groupeId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT s.legislature,
                    CASE WHEN s.uid LIKE 'VTCGR%' THEN -s.numero ELSE s.numero END AS numero,
                    CONCAT(UPPER(LEFT(s.titre, 1)), SUBSTRING(s.titre, 2)) AS titre,
                    DATE_FORMAT(s.date_scrutin, '%d-%m-%Y') AS date_scrutin,
                    v.position,
                    UPPER(dcr.title) AS decryptage_title,
                    CASE
                        WHEN vg.position_majoritaire IS NULL THEN NULL
                        WHEN v.position = vg.position_majoritaire THEN 'loyal'
                        ELSE 'rebelle'
                    END AS loyaute
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             LEFT JOIN vote_groupe vg ON vg.scrutin_id = s.id AND vg.groupe_id = :groupe
             LEFT JOIN decryptage dcr ON dcr.scrutin_id = s.id AND dcr.state = :published
             WHERE v.depute_id = :depute AND v.vote_type = :type
               AND v.position IN ('pour', 'contre', 'abstention')
             ORDER BY s.date_scrutin DESC, s.numero DESC",
            ['depute' => $deputeId, 'groupe' => $groupeId, 'type' => self::OFFICIAL, 'published' => 'published'],
        );
    }

    /**
     * Taux de participation aux scrutins publics solennels — ceux que
     * l'application d'origine retient, les votes ordinaires étant très nombreux
     * et peu représentatifs de l'assiduité.
     *
     * Le dénominateur compte TOUS les scrutins solennels tenus pendant la période
     * d'activité du député, et non les seuls scrutins où il a une ligne de vote :
     * une absence complète ne laisse aucune trace dans `vote`, et l'ignorer
     * afficherait 100 % pour un député pourtant absent plusieurs fois.
     * La période d'activité est bornée par son premier et son dernier vote connus,
     * faute d'historique des dates de mandat.
     *
     * Restreinte à une législature, la fenêtre se resserre sur les seuls
     * scrutins de celle-ci : comparer les votes d'un mandat au total de quatre
     * législatures donnerait un taux absurde.
     *
     * @return array{exprimes: int, total: int, taux: int|null}
     */
    private function participation(int $deputeId, ?int $legislature = null): array
    {
        $filtreLegislature = $legislature !== null ? ' AND s.legislature = :legislature' : '';
        $parametres = $legislature !== null ? ['legislature' => $legislature] : [];

        $fenetre = $this->connection->fetchAssociative(
            'SELECT MIN(v.scrutin_date) AS debut, MAX(v.scrutin_date) AS fin
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             WHERE v.depute_id = :depute' . $filtreLegislature,
            ['depute' => $deputeId] + $parametres,
        ) ?: [];

        if (empty($fenetre['debut']) || empty($fenetre['fin'])) {
            return ['exprimes' => 0, 'total' => 0, 'taux' => null];
        }

        $exprimes = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             WHERE v.depute_id = :depute AND v.vote_type = :type
               AND s.code_type_vote = :solennel
               AND v.position IN (\'pour\', \'contre\', \'abstention\')' . $filtreLegislature,
            ['depute' => $deputeId, 'type' => self::OFFICIAL, 'solennel' => self::SOLENNEL] + $parametres,
        );

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM scrutin s
             WHERE s.code_type_vote = :solennel AND s.date_scrutin BETWEEN :debut AND :fin' . $filtreLegislature,
            ['solennel' => self::SOLENNEL, 'debut' => $fenetre['debut'], 'fin' => $fenetre['fin']] + $parametres,
        );

        return [
            'exprimes' => $exprimes,
            'total' => $total,
            'taux' => $total > 0 ? (int) round($exprimes / $total * 100) : null,
        ];
    }

    /**
     * Taux de loyauté : part des votes exprimés conformes à la position
     * majoritaire du groupe, comparée scrutin par scrutin via la ventilation
     * historique ({@see \App\Entity\VoteGroupe}).
     *
     * Réserve : le rapprochement se fait avec le groupe actuel du député, faute
     * d'historique de ses rattachements successifs.
     *
     * @return array{conformes: int, total: int, taux: int|null}
     */
    private function loyaute(int $deputeId, int $groupeId, ?int $legislature = null): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    SUM(v.position = vg.position_majoritaire) AS conformes
             FROM vote v
             JOIN vote_groupe vg ON vg.scrutin_id = v.scrutin_id AND vg.groupe_id = :groupe
             JOIN scrutin s ON s.id = v.scrutin_id
             WHERE v.depute_id = :depute AND v.vote_type = :type
               AND v.position IN (\'pour\', \'contre\', \'abstention\')'
             . ($legislature !== null ? ' AND s.legislature = :legislature' : ''),
            ['depute' => $deputeId, 'groupe' => $groupeId, 'type' => self::OFFICIAL]
                + ($legislature !== null ? ['legislature' => $legislature] : []),
        ) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $conformes = (int) ($row['conformes'] ?? 0);

        return [
            'conformes' => $conformes,
            'total' => $total,
            'taux' => $total > 0 ? (int) round($conformes / $total * 100) : null,
        ];
    }

    /**
     * Derniers votes du député. S'appuie sur l'index (depute_id, scrutin_date)
     * pour éviter tout tri en mémoire.
     *
     * @return list<array<string, mixed>>
     */
    private function derniersVotes(int $deputeId, ?int $legislature = null): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.legislature, s.numero, s.titre, s.sort_code, v.position, v.scrutin_date,
                    dcr.title AS decryptage_title
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             LEFT JOIN decryptage dcr ON dcr.scrutin_id = s.id AND dcr.state = :published
             WHERE v.depute_id = :depute AND v.vote_type = :type'
             . ($legislature !== null ? ' AND s.legislature = :legislature' : '') . '
             ORDER BY v.scrutin_date DESC
             LIMIT 10',
            ['depute' => $deputeId, 'type' => self::OFFICIAL, 'published' => 'published']
                + ($legislature !== null ? ['legislature' => $legislature] : []),
        );
    }

    /**
     * Historique des mandats parlementaires, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    private function mandats(int $deputeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT legislature, date_debut, date_fin, departement_nom, departement_code,
                    circonscription, cause_mandat
             FROM mandat
             WHERE depute_id = :depute
             ORDER BY legislature DESC, date_debut DESC',
            ['depute' => $deputeId],
        );
    }

    /**
     * Positions du député sur les votes décryptés par la rédaction : ce sont les
     * scrutins que le site met en avant comme les plus parlants.
     *
     * @return list<array<string, mixed>>
     */
    private function positionsCles(int $deputeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT dcr.title, dcr.legislature, dcr.vote_numero, c.name AS categorie_name,
                    v.position, s.sort_code, v.scrutin_date
             FROM decryptage dcr
             JOIN scrutin s ON s.id = dcr.scrutin_id
             JOIN vote v ON v.scrutin_id = s.id AND v.depute_id = :depute AND v.vote_type = :type
             LEFT JOIN categorie c ON c.id = dcr.categorie_id
             WHERE dcr.state = :published AND v.position IN (\'pour\', \'contre\')
             ORDER BY v.scrutin_date DESC
             LIMIT 6',
            ['depute' => $deputeId, 'type' => self::OFFICIAL, 'published' => 'published'],
        );
    }
}
