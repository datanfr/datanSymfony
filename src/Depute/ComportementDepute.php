<?php

namespace App\Depute;

use Doctrine\DBAL\Connection;

/**
 * Lectures de comportement politique d'un député, partagées entre la fiche
 * publique ({@see \App\Controller\DeputeController}) et la brique embarquée
 * ({@see \App\Controller\IframeController}).
 *
 * Les deux surfaces montrent les mêmes blocs — carrousel des votes décryptés,
 * jauges de participation et de loyauté, proximité par groupe — à partir des
 * mêmes requêtes. Elles vivaient jusqu'ici en double : la fiche dans son
 * contrôleur, l'iframe recopiée dans le sien faute de pouvoir toucher au
 * premier. Ce contrat de non-modification est levé ; les lectures communes
 * sont ici, en un seul endroit.
 *
 * Tout est en DBAL, tableaux associatifs — jamais d'hydratation d'entité sur
 * une page de lecture (cf. CLAUDE.md). Les statistiques comparatives (moyennes,
 * agrégation d'accord sur 1,27 M de votes) sont précalculées par
 * {@see \App\Command\CalculStatistiquesDeputesCommand} et seulement relues ici.
 */
class ComportementDepute
{
    /** Type de vote nominatif officiel (`decompteNominatif`), le seul décompté par député. */
    private const OFFICIAL = 'decompteNominatif';

    /**
     * Positionnement éditorial d'un groupe sur l'échiquier gauche/centre/droite,
     * repris tel quel de `groups_position_edited()` de l'application d'origine —
     * une lecture de la rédaction, pas une donnée de l'open data. Sert la phrase
     * « … classé à droite de l'échiquier politique » de la carte de proximité.
     * L'axe majorité/opposition (`positionPolitique`), lui, n'existe plus en 17e
     * législature (cf. CLAUDE.md) et ne figure donc pas ici.
     */
    public const ECHIQUIER = [
        'FI' => 'à gauche', 'LFI' => 'à gauche', 'LFI-NUPES' => 'à gauche', 'LFI-NFP' => 'à gauche',
        'GDR' => 'à gauche', 'GDR-NUPES' => 'à gauche', 'SOC' => 'à gauche', 'SOC-A' => 'à gauche',
        'SER' => 'à gauche', 'SRC' => 'à gauche', 'NG' => 'à gauche', 'ECOLO' => 'à gauche',
        'ECOS' => 'à gauche', 'RRDP' => 'à gauche',
        'MODEM' => 'au centre', 'DEM' => 'au centre', 'LAREM' => 'au centre', 'RE' => 'au centre',
        'RENAIS' => 'au centre', 'LT' => 'au centre', 'LIOT' => 'au centre', 'EDS' => 'au centre',
        'AGIR-E' => 'au centre', 'UDI_I' => 'au centre', 'UDI-AGIR' => 'au centre', 'EPR' => 'au centre',
        'LR' => 'à droite', 'DR' => 'à droite', 'LC' => 'à droite', 'UDI' => 'à droite',
        'UDI-I' => 'à droite', 'UDI-A-I' => 'à droite', 'LES-REP' => 'à droite', 'R-UMP' => 'à droite',
        'UMP' => 'à droite', 'RN' => 'à droite', 'HORIZONS' => 'à droite', 'HOR' => 'à droite',
        'AD' => 'à droite', 'UDR' => 'à droite', 'UDDPLR' => 'à droite',
    ];

    /**
     * Les « positions importantes » ne sont pas des votes décryptés quelconques :
     * c'est une sélection éditoriale figée de scrutins marquants, codée en dur dans
     * `Votes_model::get_key_votes_mp()`, avec sa reformulation propre. On la reprend
     * telle quelle. Deux relèvent de la 16e législature, dont nous n'avons AUCUN vote
     * nominatif (`vote` ne couvre que la 17e, cf. CLAUDE.md) : ces lignes restent donc
     * absentes de la fiche, faute de donnée — elles ne se fabriquent pas.
     */
    private const VOTES_CLES = [
        ['legislature' => 16, 'numero' => 629, 'texte' => "l'inscription de l'interruption volontaire de grossesse (IVG) dans la Constitution"],
        ['legislature' => 16, 'numero' => 3213, 'texte' => 'du projet de loi immigration en 2023'],
        ['legislature' => 17, 'numero' => 2107, 'texte' => "la proposition de loi créant un droit à l'aide à mourir"],
        ['legislature' => 17, 'numero' => 3260, 'texte' => 'la proposition du RN visant à dénoncer les accords franco-algériens de 1968'],
        ['legislature' => 17, 'numero' => 3300, 'texte' => 'la taxe Zucman sur les patrimoines supérieurs à 100 millions d\'euros'],
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Votes décryptés sur lesquels le député s'est prononcé, avec son
     * explication de vote quand il en a publié une.
     *
     * Un scrutin où il n'a pas de ligne `vote` n'apparaît pas : l'application
     * d'origine écarte aussi les non-votants (`vs.vote != 'nv'`), une absence
     * n'étant pas une position.
     *
     * Le carrousel de la fiche (`_votes.php`, `get_votes_datan_depute($mp, 5)`)
     * n'en veut que les cinq plus récents ; la page « tous ses votes » les veut
     * tous. Même requête, un `LIMIT` optionnel les sépare.
     *
     * @return list<array<string, mixed>>
     */
    public function votesDecryptes(int $deputeId, ?int $limite = null): array
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
             ORDER BY s.date_scrutin DESC'
             . ($limite !== null ? ' LIMIT ' . $limite : ''),
            ['depute' => $deputeId, 'published' => 'published'],
        );
    }

    /**
     * Positions du député sur les votes décryptés par la rédaction : ce sont les
     * scrutins que le site met en avant comme les plus parlants.
     *
     * @return list<array<string, mixed>>
     */
    public function positionsCles(int $deputeId): array
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

    /**
     * Le bloc « Ses positions importantes » (`_key_positions.php`) : la sélection
     * éditoriale figée {@see self::VOTES_CLES}, avec la position du député sur
     * chacun de ces scrutins et le fait qu'il ait voté ou non comme son groupe.
     *
     * La loyauté (« a voté comme son groupe ») se lit sur la position majoritaire
     * du groupe **au moment du scrutin**, résolu par `fonction_groupe` à la date
     * du vote — comme le `mandat_groupe` du legacy (daily.php:1966). Prendre le
     * groupe d'aujourd'hui casserait les deux scrutins de la 16e législature (le
     * groupe courant n'y existait pas) et les changeurs de groupe.
     *
     * L'ordre éditorial de {@see self::VOTES_CLES} est conservé ; un scrutin sans
     * vote nominatif pour ce député est simplement omis.
     *
     * **Le non-votant est rendu « abstention »**, comme à l'origine. `votes_scores`
     * y est un `varchar` où le non-votant s'écrit `'nv'` ; le `CASE WHEN vs.vote = 0
     * THEN "abstention"` de `get_key_votes_mp()` compare une chaîne à un entier,
     * MariaDB convertit `'nv'` en `0`, et les 28 non-votants des cinq scrutins clés
     * ressortent donc « ABSTENTION ». Reprendre notre `nonVotant` tel quel afficherait
     * un badge sans style — `sort-nonVotant` n'existe dans aucune des deux feuilles —
     * au-dessus d'une phrase qui, elle, dit déjà « s'est abstenu ».
     *
     * La normalisation ne vaut que pour l'affichage : `comme_groupe` se décide sur la
     * position BRUTE. Sans quoi un non-votant dont le groupe s'est majoritairement
     * abstenu passerait pour loyal, alors que l'origine met `scoreLoyaute` à `NULL`
     * dès que le vote est `'nv'` (daily.php:1981) et écrit « n'a pas voté comme son
     * groupe ».
     *
     * @return list<array{legislature: int, numero: int, texte: string, position: string, comme_groupe: bool}>
     */
    public function positionsImportantes(int $deputeId): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            'SELECT s.legislature, s.numero, v.position,
                    vg.position_majoritaire AS position_groupe
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             LEFT JOIN fonction_groupe fg ON fg.depute_id = v.depute_id AND fg.nomin_principale = 1
               AND fg.date_debut <= s.date_scrutin
               AND (fg.date_fin IS NULL OR fg.date_fin >= s.date_scrutin)
             LEFT JOIN vote_groupe vg ON vg.scrutin_id = s.id AND vg.groupe_id = fg.groupe_id
             WHERE v.depute_id = :depute AND v.vote_type = :type
               AND ((s.legislature = 16 AND s.numero IN (629, 3213))
                 OR (s.legislature = 17 AND s.numero IN (2107, 3260, 3300)))',
            ['depute' => $deputeId, 'type' => self::OFFICIAL],
        );

        $parCle = [];
        foreach ($lignes as $ligne) {
            $parCle[$ligne['legislature'] . '-' . $ligne['numero']] = $ligne;
        }

        $positions = [];
        foreach (self::VOTES_CLES as $cle) {
            $ligne = $parCle[$cle['legislature'] . '-' . $cle['numero']] ?? null;
            if ($ligne === null) {
                continue;
            }

            $positions[] = [
                'legislature' => $cle['legislature'],
                'numero' => $cle['numero'],
                'texte' => $cle['texte'],
                'position' => \in_array($ligne['position'], ['pour', 'contre'], true)
                    ? (string) $ligne['position']
                    : 'abstention',
                'comme_groupe' => $ligne['position_groupe'] !== null
                    && $ligne['position'] === $ligne['position_groupe'],
            ];
        }

        return $positions;
    }

    /**
     * Statistiques de comportement de la fiche, lues sur les tables précalculées
     * par {@see \App\Command\CalculStatistiquesDeputesCommand}. Reproduit
     * `Depute_service::get_statistics()` : la carte de participation et celle de
     * loyauté ne s'affichent qu'au-delà de dix votes (`votesN >= 10`), et
     * comparent le score du député à la moyenne de tous les députés et de son
     * groupe. Rendu null si le député n'a pas de ligne — une fiche sans votes
     * nominatifs, donc d'avant la 17e législature, se tait.
     *
     * @return array<string, mixed>|null
     */
    public function statistiques(int $deputeId, ?int $groupeId, int $legislature): ?array
    {
        $stat = $this->connection->fetchAssociative(
            'SELECT participation_score, participation_votes, loyaute_score, loyaute_votes
             FROM statistique_depute WHERE depute_id = :depute AND legislature = :legislature',
            ['depute' => $deputeId, 'legislature' => $legislature],
        );

        if ($stat === false) {
            return null;
        }

        $resultat = [];

        if ($stat['participation_score'] !== null && (int) $stat['participation_votes'] >= 10) {
            $score = (int) $stat['participation_score'];
            $moyennes = $this->moyennesStatistique('participation_score', $groupeId, $legislature);
            $resultat['participation'] = [
                'score' => $score,
                'all' => $moyennes['all'],
                'group' => $moyennes['group'],
                'edito_all' => $this->comparerStatistique($score, $moyennes['all'], 'souvent'),
                'edito_group' => $this->comparerStatistique($score, $moyennes['group'], 'souvent'),
            ];
        }

        if ($stat['loyaute_score'] !== null && (int) $stat['loyaute_votes'] >= 10) {
            $score = (int) $stat['loyaute_score'];
            $moyennes = $this->moyennesStatistique('loyaute_score', $groupeId, $legislature);
            $resultat['loyaute'] = [
                'score' => $score,
                'all' => $moyennes['all'],
                'group' => $moyennes['group'],
                'edito_all' => $this->comparerStatistique($score, $moyennes['all'], 'eleve'),
                'edito_group' => $this->comparerStatistique($score, $moyennes['group'], 'eleve'),
                // Le repli « Ce score ne prend en compte que le dernier groupe… » :
                // la loyauté du député envers chacun de ses rattachements successifs
                // (deputes_loyaute par mandatId chez l'origine, qui l'affiche même
                // pour un député resté fidèle à un seul groupe).
                'historique' => $this->loyauteParRattachement($deputeId, $legislature),
            ];
        }

        $accord = $this->accordGroupes($deputeId, $groupeId, $legislature);
        if ($accord !== null) {
            $resultat['accord'] = $accord;
        }

        return $resultat !== [] ? $resultat : null;
    }

    /**
     * Loyauté du député envers chaque groupe auquel il a appartenu pendant la
     * législature : ses votes exprimés pendant chaque rattachement principal
     * (`fonction_groupe`), confrontés à la position majoritaire de CE groupe.
     * Reproduit `deputes_loyaute` agrégée par `mandatId` (daily.php:2501-2513).
     * Volume : les votes d'un seul député — la fiche est en cache une heure.
     *
     * @return list<array{libelle: string, libelle_abrev: string, score: int}>
     */
    private function loyauteParRattachement(int $deputeId, int $legislature): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT g.libelle, g.libelle_abrev,
                    ROUND(AVG(v.position = vg.position_majoritaire) * 100) AS score
             FROM fonction_groupe fg
             JOIN groupe g ON g.id = fg.groupe_id AND g.legislature = :legislature
             JOIN vote v ON v.depute_id = fg.depute_id AND v.vote_type = :type
               AND v.position IN ('pour', 'contre', 'abstention')
             JOIN scrutin s ON s.id = v.scrutin_id
               AND s.date_scrutin >= fg.date_debut
               AND (fg.date_fin IS NULL OR s.date_scrutin <= fg.date_fin)
             JOIN vote_groupe vg ON vg.scrutin_id = v.scrutin_id AND vg.groupe_id = fg.groupe_id
             WHERE fg.depute_id = :depute AND fg.nomin_principale = 1
             GROUP BY fg.id, g.libelle, g.libelle_abrev
             ORDER BY MIN(fg.date_debut)",
            ['depute' => $deputeId, 'legislature' => $legislature, 'type' => self::OFFICIAL],
        );
    }

    /**
     * Moyenne d'une statistique sur tous les députés en exercice, et sur les seuls
     * députés du groupe. `$colonne` est une valeur interne (`participation_score`
     * ou `loyaute_score`), jamais une entrée utilisateur.
     *
     * @return array{all: int|null, group: int|null}
     */
    private function moyennesStatistique(string $colonne, ?int $groupeId, int $legislature): array
    {
        $all = $this->connection->fetchOne(
            "SELECT ROUND(AVG($colonne)) FROM statistique_depute
             WHERE legislature = :legislature AND actif = 1 AND $colonne IS NOT NULL",
            ['legislature' => $legislature],
        );

        $group = $groupeId === null ? null : $this->connection->fetchOne(
            "SELECT ROUND(AVG(sd.$colonne)) FROM statistique_depute sd
             JOIN depute d ON d.id = sd.depute_id
             WHERE sd.legislature = :legislature AND sd.actif = 1 AND sd.$colonne IS NOT NULL
               AND d.groupe_id = :groupe",
            ['legislature' => $legislature, 'groupe' => $groupeId],
        );

        return [
            'all' => $all !== false && $all !== null ? (int) $all : null,
            'group' => $group !== false && $group !== null ? (int) $group : null,
        ];
    }

    /**
     * « plus/moins/autant » du député face à une moyenne (`Depute_edito`). La
     * loyauté se dit « plus/moins élevé », la participation « plus/moins souvent ».
     */
    private function comparerStatistique(?int $score, ?int $moyenne, string $registre): ?string
    {
        if ($score === null || $moyenne === null) {
            return null;
        }

        if ($registre === 'souvent') {
            return $score < $moyenne ? 'moins souvent' : ($score > $moyenne ? 'plus souvent' : 'autant');
        }

        return $score < $moyenne ? 'moins élevé' : ($score > $moyenne ? 'plus élevé' : 'aussi élevé');
    }

    /**
     * Proximité du député avec chaque groupe (`deputes_accord_cleaned`).
     *
     * Pour les barres : les groupes encore actifs, hors non-inscrits, au-delà de
     * dix votes comparables, triés par proximité décroissante — puis les trois du
     * haut (« souvent ») et les trois du bas (« rarement »), comme le service
     * d'origine. Pour la phrase éditoriale : le groupe le plus proche et le moins
     * proche AUTRES que le sien. Pour le classement dépliable : tous les groupes,
     * dissous compris.
     *
     * @return array<string, mixed>|null
     */
    private function accordGroupes(int $deputeId, ?int $groupeId, int $legislature): ?array
    {
        $actifs = $this->connection->fetchAllAssociative(
            "SELECT g.id, g.libelle, g.libelle_abrev, g.couleur, a.accord, a.votes_n
             FROM accord_groupe a
             JOIN groupe g ON g.id = a.groupe_id
             WHERE a.depute_id = :depute AND a.legislature = :legislature
               AND g.date_fin IS NULL AND g.libelle_abrev <> 'NI' AND a.votes_n > 10
             ORDER BY a.accord DESC, g.libelle_abrev",
            ['depute' => $deputeId, 'legislature' => $legislature],
        );

        if ($actifs === []) {
            return null;
        }

        // Découpage du legacy : moitié haute puis trois premiers, moitié basse puis
        // trois derniers (rendus dans l'ordre croissant pour la seconde barre).
        $moitie = (int) round(\count($actifs) / 2, 0, \PHP_ROUND_HALF_UP);
        $premiers = \array_slice(\array_slice($actifs, 0, $moitie), 0, 3);
        $derniers = array_reverse(\array_slice(\array_slice($actifs, $moitie), -3));

        // Phrase éditoriale : plus proche et moins proche hors de son propre groupe.
        $autres = array_values(array_filter($actifs, fn (array $g) => (int) $g['id'] !== $groupeId));
        $proximite = null;
        if ($autres !== []) {
            $plusProche = $autres[0];
            $moinsProche = $autres[\count($autres) - 1];
            $proximite = [
                'plus_proche' => $plusProche + ['echiquier' => self::ECHIQUIER[$plusProche['libelle_abrev']] ?? null],
                'moins_proche' => $moinsProche + ['echiquier' => self::ECHIQUIER[$moinsProche['libelle_abrev']] ?? null],
            ];
        }

        $tous = $this->connection->fetchAllAssociative(
            "SELECT g.libelle, g.libelle_abrev, a.accord, a.votes_n,
                    CASE WHEN g.date_fin IS NULL THEN 0 ELSE 1 END AS dissous
             FROM accord_groupe a
             JOIN groupe g ON g.id = a.groupe_id
             WHERE a.depute_id = :depute AND a.legislature = :legislature
             ORDER BY a.accord DESC, g.libelle_abrev",
            ['depute' => $deputeId, 'legislature' => $legislature],
        );

        return [
            'premiers' => $premiers,
            'derniers' => $derniers,
            'proximite' => $proximite,
            'tous' => $tous,
        ];
    }
}
