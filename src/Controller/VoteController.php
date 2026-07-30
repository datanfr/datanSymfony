<?php

namespace App\Controller;

use App\Legislature;
use App\Referencement\OpenGraph;
use App\TypeVoteEdito;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques des scrutins, portées depuis le contrôleur Votes de
 * l'application CodeIgniter d'origine.
 *
 * Les agrégats (ventilation par groupe, cohésion, participation) sont calculés
 * en SQL : sur un scrutin, la table `vote` contient ~575 lignes et l'hydratation
 * d'autant d'entités Doctrine coûterait bien plus cher que la requête elle-même.
 */
class VoteController extends AbstractController
{
    /** Seules les lignes de vote officielles entrent dans les décomptes. */
    private const OFFICIAL = 'decompteNominatif';

    /** Durée de cache des pages de scrutin, reprise de l'application d'origine (3 jours). */
    private const CACHE_TTL = 259200;

    /** Préfixe d'uid d'un scrutin de l'Assemblée : « VTANR5L17V1234 ». */
    private const PREFIXE_ASSEMBLEE = 'VTANR';

    /** Préfixe d'uid d'un vote du Congrès : « VTCGR5L16V1 ». */
    private const PREFIXE_CONGRES = 'VTCGR';

    public function __construct(
        private readonly Connection $connection,
        private readonly OpenGraph $openGraph,
    ) {
    }

    #[Route('/votes/legislature-{legislature}/vote_{numero}', name: 'vote_individual', requirements: ['legislature' => '\d+', 'numero' => '\d+'], methods: ['GET'])]
    public function individual(int $legislature, int $numero): Response
    {
        return $this->afficheScrutin($legislature, $numero, self::PREFIXE_ASSEMBLEE);
    }

    /**
     * Vote du Congrès, réuni à Versailles pour réviser la Constitution.
     *
     * Il est numéroté dans sa propre série : le scrutin n° 1 de la 16e
     * législature existe deux fois, à l'Assemblée et au Congrès. D'où l'adresse
     * distincte `vote_c1`, et le préfixe d'uid comme seul discriminant fiable —
     * sans lui, la page du scrutin ordinaire tomberait au hasard sur l'un ou
     * l'autre.
     */
    #[Route('/votes/legislature-{legislature}/vote_c{numero}', name: 'vote_congres', requirements: ['legislature' => '\d+', 'numero' => '\d+'], methods: ['GET'])]
    public function congres(int $legislature, int $numero): Response
    {
        return $this->afficheScrutin($legislature, $numero, self::PREFIXE_CONGRES);
    }

    /**
     * Page du scrutin mettant en avant l'explication d'un député donné.
     *
     * C'est le lien qu'un député partage sur les réseaux sociaux depuis son
     * espace : la page est la même, à ceci près qu'elle désigne son auteur.
     * Une explication retirée ou repassée en brouillon renvoie à la page
     * ordinaire plutôt qu'à un 404 — l'adresse a pu être partagée, et le
     * scrutin, lui, existe toujours.
     *
     * Les deux formes du legacy sont reprises, `vote_{n}` et `vote_c{n}` : le
     * Congrès a la sienne, comme pour la page de scrutin elle-même.
     */
    #[Route(
        '/votes/legislature-{legislature}/vote_{numero}/explication_{mp_id}',
        name: 'vote_explication',
        requirements: ['legislature' => '\d+', 'numero' => '\d+', 'mp_id' => '[A-Za-z0-9]+'],
        methods: ['GET'],
    )]
    public function explication(int $legislature, int $numero, string $mp_id): Response
    {
        return $this->afficheScrutin($legislature, $numero, self::PREFIXE_ASSEMBLEE, $mp_id);
    }

    #[Route(
        '/votes/legislature-{legislature}/vote_c{numero}/explication_{mp_id}',
        name: 'vote_congres_explication',
        requirements: ['legislature' => '\d+', 'numero' => '\d+', 'mp_id' => '[A-Za-z0-9]+'],
        methods: ['GET'],
    )]
    public function explicationCongres(int $legislature, int $numero, string $mp_id): Response
    {
        return $this->afficheScrutin($legislature, $numero, self::PREFIXE_CONGRES, $mp_id);
    }

    private function afficheScrutin(int $legislature, int $numero, string $prefixe, ?string $mpEnAvant = null): Response
    {
        $scrutin = $this->connection->fetchAssociative(
            'SELECT s.*, d.title AS decryptage_title, d.description AS decryptage_description,
                    d.slug AS decryptage_slug, d.state AS decryptage_state,
                    c.name AS categorie_name, c.slug AS categorie_slug,
                    l.name AS lecture_name,
                    dos.titre AS dossier_titre, dos.titre_chemin AS dossier_chemin,
                    dos.legislature AS dossier_legislature,
                    dos.procedure_parlementaire AS dossier_procedure,
                    a.href AS amendement_href, a.expose AS amendement_expose,
                    a.resume_ia AS amendement_resume, a.resume_relu AS amendement_resume_relu
             FROM scrutin s
             LEFT JOIN decryptage d ON d.scrutin_id = s.id AND d.state = :published
             LEFT JOIN categorie c ON c.id = d.categorie_id
             LEFT JOIN lecture l ON l.id = d.lecture_id
             LEFT JOIN dossier dos ON dos.id = s.dossier_id
             LEFT JOIN amendement a ON a.id = s.amendement_id
             WHERE s.legislature = :legislature AND s.numero = :numero AND s.uid LIKE :prefixe
             LIMIT 1',
            ['legislature' => $legislature, 'numero' => $numero, 'prefixe' => $prefixe . '%', 'published' => 'published'],
        );

        if ($scrutin === false) {
            throw $this->createNotFoundException('Scrutin introuvable.');
        }

        $explications = $this->explications((int) $scrutin['id']);

        if ($mpEnAvant !== null && !\in_array($mpEnAvant, array_column($explications, 'mp_id'), true)) {
            return $this->redirectToRoute(
                $prefixe === self::PREFIXE_CONGRES ? 'vote_congres' : 'vote_individual',
                ['legislature' => $legislature, 'numero' => $numero],
            );
        }

        $groupes = $this->groupBreakdown((int) $scrutin['id']);

        // Position majoritaire par groupe, pour en déduire la loyauté de chaque député.
        $majorityByGroupe = [];
        foreach ($groupes as $groupe) {
            $majorityByGroupe[$groupe['groupe_id']] = $groupe['positionMajoritaire'];
        }

        $numeroAffiche = ($prefixe === self::PREFIXE_CONGRES ? -1 : 1) * $numero;

        $response = $this->render('vote/individual.html.twig', [
            'scrutin' => $scrutin,
            // « Type de vote » de l'encart Infos : le libellé éditorial du site
            // (« amendement », « projet de loi »…) et son info-bulle, et non le
            // code de scrutin de l'Assemblée. Cf. TypeVoteEdito.
            'type_edito' => TypeVoteEdito::pour(
                $scrutin['nature_vote'] ?? null,
                $scrutin['dossier_procedure'] ?? null,
            ),
            // Navigation « Précédent / Tous les votes / Suivant » du pied de page :
            // le scrutin voisin par numéro, en sautant les trous de numérotation.
            'voisins' => $this->voisins($legislature, $numero, $prefixe),
            // Carrousel « Les derniers votes décryptés par Datan » en pied de page.
            'derniers_decryptes' => $this->derniersDecryptes(),
            // Le Congrès a sa propre numérotation, affichée « c1 » ; le signe du
            // numéro ne le dit pas, la table `scrutin` le stocke positif.
            'numero_affiche' => $numeroAffiche,
            'groupes' => $groupes,
            'deputes' => $this->deputyVotes((int) $scrutin['id'], $majorityByGroupe),
            'percentages' => $this->percentages($scrutin),
            'date_scrutin_fr' => $this->frenchDate($scrutin['date_scrutin'] ?? null),
            'explications' => $explications,
            'explication_en_avant' => $mpEnAvant,
            'ogp' => $this->ogp($scrutin, $numeroAffiche, $mpEnAvant, $explications),
            'fil_ariane' => [
                ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
                ['nom' => 'Votes', 'url' => $this->generateUrl('votes_index')],
                ['nom' => $legislature . 'e législature', 'url' => $this->generateUrl('votes_legislature', ['legislature' => $legislature])],
                [
                    'nom' => 'Vote n° ' . ($numeroAffiche > 0 ? $numeroAffiche : 'c' . $numero),
                    'url' => $this->generateUrl(
                        $prefixe === self::PREFIXE_CONGRES ? 'vote_congres' : 'vote_individual',
                        ['legislature' => $legislature, 'numero' => $numero],
                    ),
                ],
            ],
        ]);

        // Un scrutin passé ne bouge plus : l'application d'origine mettait ces pages
        // en cache 3 jours. On confie ici le même rôle au cache HTTP partagé
        // (reverse proxy / CDN), avec un ETag pour permettre la revalidation.
        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);
        $response->setEtag(md5($response->getContent() ?: ''));

        return $response;
    }

    /**
     * Scrutins voisins par numéro, pour la barre « Précédent / Suivant ».
     *
     * La numérotation a des trous (scrutins annulés) : l'application d'origine
     * décrémente puis incrémente en boucle jusqu'à tomber sur un scrutin
     * existant. Une seule requête suffit ici — MAX en dessous, MIN au-dessus.
     *
     * @return array{precedent: int|null, suivant: int|null}
     */
    private function voisins(int $legislature, int $numero, string $prefixe): array
    {
        $ligne = $this->connection->fetchAssociative(
            'SELECT MAX(CASE WHEN numero < :numero THEN numero END) AS precedent,
                    MIN(CASE WHEN numero > :numero THEN numero END) AS suivant
             FROM scrutin
             WHERE legislature = :legislature AND uid LIKE :prefixe',
            ['legislature' => $legislature, 'numero' => $numero, 'prefixe' => $prefixe . '%'],
        ) ?: [];

        return [
            'precedent' => isset($ligne['precedent']) ? (int) $ligne['precedent'] : null,
            'suivant' => isset($ligne['suivant']) ? (int) $ligne['suivant'] : null,
        ];
    }

    /**
     * Les cinq derniers votes décryptés, pour le carrousel de pied de page.
     *
     * @return list<array<string, mixed>>
     */
    private function derniersDecryptes(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.title, d.legislature, d.vote_numero,
                    s.date_scrutin, s.sort_code,
                    c.name AS categorie_name, l.name AS lecture_name
             FROM decryptage d
             JOIN scrutin s ON s.id = d.scrutin_id
             LEFT JOIN categorie c ON c.id = d.categorie_id
             LEFT JOIN lecture l ON l.id = d.lecture_id
             WHERE d.state = :published
             ORDER BY s.date_scrutin DESC, d.vote_numero DESC
             LIMIT 5',
            ['published' => 'published'],
        );
    }

    /**
     * Tous les votes décryptés, filtrables par thématique.
     *
     * La liste n'est pas paginée et ne l'a jamais été : le filtrage se fait
     * côté client (Isotope), sur la totalité des cartes. C'est le cœur
     * éditorial du site — 235 décryptages aujourd'hui, une croissance de
     * quelques dizaines par an.
     */
    #[Route('/votes/decryptes', name: 'votes_decryptes', methods: ['GET'])]
    public function decryptes(): Response
    {
        $decryptages = $this->connection->fetchAllAssociative(
            'SELECT d.title, d.slug, d.legislature, d.vote_numero,
                    c.name AS categorie_name, c.slug AS categorie_slug,
                    l.name AS lecture_name,
                    s.date_scrutin, s.sort_code
             FROM decryptage d
             JOIN scrutin s ON s.id = d.scrutin_id
             LEFT JOIN categorie c ON c.id = d.categorie_id
             LEFT JOIN lecture l ON l.id = d.lecture_id
             WHERE d.state = :published
             ORDER BY d.legislature DESC, s.date_scrutin DESC, d.vote_numero DESC',
            ['published' => 'published'],
        );

        $response = $this->render('vote/decryptes.html.twig', [
            'decryptages' => $decryptages,
            'categories' => $this->categoriesActives(),
            // Le compteur de tête ne porte que sur la législature en cours,
            // là où les vignettes de thématique comptent toutes législatures
            // confondues. L'écart vient de l'application d'origine ; il est
            // voulu : l'accroche parle du travail en cours.
            'nombre_votes' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM decryptage WHERE state = :published AND legislature = :legislature',
                ['published' => 'published', 'legislature' => Legislature::COURANTE],
            ),
            'legislature' => Legislature::COURANTE,
            'fil_ariane' => [
                ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
                ['nom' => 'Votes', 'url' => $this->generateUrl('votes_index')],
                ['nom' => 'Votes décryptés', 'url' => $this->generateUrl('votes_decryptes')],
            ],
        ]);

        // La liste bouge à chaque nouveau décryptage publié : cache plus court
        // que les pages de scrutin, qui elles sont figées.
        $response->setPublic();
        $response->setSharedMaxAge(3600);

        return $response;
    }

    /**
     * Les thématiques qui portent au moins un décryptage publié, et leur
     * nombre. Une catégorie sans décryptage n'apparaît pas : le filtre
     * n'offrirait qu'une liste vide.
     *
     * @return list<array<string, mixed>>
     */
    private function categoriesActives(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.name, c.slug, c.libelle, COUNT(*) AS nombre
             FROM decryptage d
             JOIN categorie c ON c.id = d.categorie_id
             WHERE d.state = :published
             GROUP BY c.id
             ORDER BY c.name',
            ['published' => 'published'],
        );
    }

    /**
     * Ventilation d'un scrutin par groupe parlementaire.
     *
     * On lit la ventilation historique (table vote_groupe) et non un agrégat des
     * votes individuels : l'appartenance d'un député à un groupe évolue, et
     * recalculer à partir de `depute.groupe_id` (appartenance courante) exclurait
     * les votants ayant depuis quitté leur groupe — les totaux ne correspondraient
     * alors plus à ceux du scrutin.
     *
     * La cohésion reprend l'indice d'accord de l'application d'origine :
     * (max(P,C,A) − 0,5 × (exprimés − max(P,C,A))) / exprimés, arrondi à 3 décimales.
     *
     * @return list<array<string, mixed>>
     */
    private function groupBreakdown(int $scrutinId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id AS groupe_id, g.libelle, g.libelle_abrev, g.legislature, g.couleur,
                    vg.nombre_membres_groupe AS effectif,
                    vg.position_majoritaire AS positionMajoritaire,
                    vg.nombre_pours AS nombrePours,
                    vg.nombre_contres AS nombreContres,
                    vg.nombre_abstentions AS nombreAbstentions,
                    vg.non_votants AS nonVotants
             FROM vote_groupe vg
             JOIN groupe g ON g.id = vg.groupe_id
             WHERE vg.scrutin_id = :scrutin
             ORDER BY vg.nombre_membres_groupe DESC',
            ['scrutin' => $scrutinId],
        );

        foreach ($rows as &$row) {
            $pour = (int) $row['nombrePours'];
            $contre = (int) $row['nombreContres'];
            $abstention = (int) $row['nombreAbstentions'];
            $exprimes = $pour + $contre + $abstention;
            $effectif = max(1, (int) $row['effectif']);
            $max = max($pour, $contre, $abstention);

            $row['positionMajoritaire'] = $row['positionMajoritaire'] ?: 'nv';
            $row['percentageVotants'] = (int) round($exprimes / $effectif * 100);
            $row['cohesion'] = $exprimes > 0 ? round(($max - 0.5 * ($exprimes - $max)) / $exprimes, 3) : 0;
        }

        return $rows;
    }

    /**
     * Votes nominatifs d'un scrutin, avec la loyauté de chaque député
     * (a-t-il suivi la position majoritaire de son groupe ?).
     *
     * @param array<int, string> $majorityByGroupe
     *
     * @return list<array<string, mixed>>
     */
    private function deputyVotes(int $scrutinId, array $majorityByGroupe): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.firstname, d.lastname, d.slug, d.dpt_slug, d.groupe_id,
                    g.libelle AS groupe_libelle, g.libelle_abrev AS groupe_abrev,
                    v.position, v.cause_position
             FROM vote v
             JOIN depute d ON d.id = v.depute_id
             LEFT JOIN groupe g ON g.id = d.groupe_id
             WHERE v.scrutin_id = :scrutin AND v.vote_type = :type
             ORDER BY d.lastname, d.firstname',
            ['scrutin' => $scrutinId, 'type' => self::OFFICIAL],
        );

        foreach ($rows as &$row) {
            $majority = $majorityByGroupe[$row['groupe_id']] ?? null;
            $row['loyaute'] = match (true) {
                $majority === null, $row['position'] === null, $row['position'] === 'nonVotant' => null,
                $row['position'] === $majority => 'loyal',
                default => 'rebelle',
            };
        }

        return $rows;
    }

    /**
     * Carte Open Graph du scrutin — les trois cas de l'origine : un vote
     * décrypté a sa carte composée (résultat en chiffres, ou portrait du député
     * quand son explication est mise en avant) ; un vote final non décrypté ne
     * change que son titre, sur le dossier ; le reste garde la carte générique
     * que base.html.twig fabrique seul.
     *
     * @param array<string, mixed> $scrutin
     * @param list<array<string, mixed>> $explications
     *
     * @return array<string, mixed>
     */
    private function ogp(array $scrutin, int $numeroAffiche, ?string $mpEnAvant, array $explications): array
    {
        if ($scrutin['decryptage_title'] === null) {
            return $scrutin['nature_vote'] === 'final' && $scrutin['dossier_titre'] !== null
                ? ['titre' => 'Assemblée nationale : ' . $scrutin['dossier_titre'] . ' - Vote final']
                : [];
        }

        $titre = 'Vote Assemblée nationale : ' . $scrutin['decryptage_title'] . ' | Datan';

        if ($mpEnAvant !== null) {
            foreach ($explications as $explication) {
                if ($explication['mp_id'] === $mpEnAvant) {
                    return ['titre' => $titre] + $this->openGraph->pourExplication($explication, $scrutin['decryptage_title']);
                }
            }
        }

        return ['titre' => $titre] + $this->openGraph->pourVote($scrutin, $scrutin['decryptage_title'], $numeroAffiche);
    }

    /**
     * Explications de vote publiées par les députés sur ce scrutin,
     * avec la position qu'ils ont effectivement exprimée.
     *
     * @return list<array<string, mixed>>
     */
    private function explications(int $scrutinId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT e.texte, d.firstname, d.lastname, d.slug, d.mp_id,
                    g.libelle_abrev AS groupe_abrev, g.couleur AS groupe_couleur,
                    v.position
             FROM explication e
             JOIN depute d ON d.id = e.depute_id
             LEFT JOIN groupe g ON g.id = d.groupe_id
             LEFT JOIN vote v ON v.depute_id = e.depute_id AND v.scrutin_id = e.scrutin_id AND v.vote_type = :type
             WHERE e.scrutin_id = :scrutin AND e.publiee = 1
             ORDER BY e.modified_at DESC',
            ['scrutin' => $scrutinId, 'type' => self::OFFICIAL],
        );
    }

    private function frenchDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $mois = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        try {
            $dt = new \DateTimeImmutable($date);
        } catch (\Exception) {
            return '';
        }

        return sprintf('%d %s %d', (int) $dt->format('j'), $mois[(int) $dt->format('n')], (int) $dt->format('Y'));
    }

    /**
     * @param array<string, mixed> $scrutin
     *
     * @return array<string, int>
     */
    private function percentages(array $scrutin): array
    {
        $pour = (int) $scrutin['nombre_pour'];
        $contre = (int) $scrutin['nombre_contre'];
        $abstention = (int) $scrutin['nombre_abstentions'];
        $total = max(1, $pour + $contre + $abstention);

        return [
            'pour' => (int) round($pour / $total * 100),
            'contre' => (int) round($contre / $total * 100),
            'abstention' => (int) round($abstention / $total * 100),
        ];
    }
}
