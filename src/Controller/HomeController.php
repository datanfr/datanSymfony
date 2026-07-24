<?php

namespace App\Controller;

use App\CouleurGroupe;
use App\Legislature;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'accueil (Home::index de l'application CodeIgniter d'origine,
 * vue application/views/home/index.php).
 */
class HomeController extends AbstractController
{
    private const CACHE_TTL = 3600;

    private const NON_INSCRITS = 'NI';

    /**
     * Blocs politiques de l'hémicycle, repris tels quels de
     * Groupes_model::get_blocs().
     */
    private const BLOCS = [
        'gauche' => ['LFI-NFP', 'SOC', 'ECOS', 'GDR'],
        'centre' => ['EPR', 'DEM', 'HOR'],
        'droite' => ['DR'],
        'extreme_droite' => ['RN', 'UDR', 'UDDPLR'],
    ];

    /** Mois abrégés du site d'origine (months_abbrev()). */
    private const MOIS_ABREGES = [
        1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
        'juill.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
    ];

    /**
     * Ordre des groupes dans l'hémicycle, de la gauche à la droite de
     * l'hémicycle (Groupes_model::get_groupes_sorted()).
     */
    private const ORDRE_HEMICYCLE = [
        'GDR', 'LFI-NFP', 'SOC', 'ECOS', 'EPR', 'DEM', 'HOR', 'LIOT', 'DR', 'UDDPLR', 'RN', 'NI',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        $groupes = $this->groupesHemicycle(Legislature::COURANTE);

        $response = $this->render('home/index.html.twig', [
            'legislature' => Legislature::COURANTE,
            'groupes' => $groupes,
            // Les non-inscrits ne comptent pas comme un groupe politique.
            'nombre_groupes' => count(array_filter($groupes, static fn (array $g) => $g['libelle_abrev'] !== self::NON_INSCRITS)),
            'blocs' => $this->blocs($groupes),
            'hemicycle' => $this->hemicycle($groupes),
            'effectif_total' => array_sum(array_column($groupes, 'effectif')),
            'decryptages' => $this->derniersDecryptages(),
            'explications' => $this->dernieresExplications(),
            'placeholder' => $this->placeholderRecherche(),
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Exemple glissé dans le champ de recherche : un député en exercice ou un
     * groupe courant, à pile ou face — le tirage de l'application d'origine
     * (`rand(0, 10) < 5`), repris tel quel. Le cache HTTP fige l'exemple une
     * heure ; l'origine, derrière son propre cache, vit la même chose.
     */
    private function placeholderRecherche(): string
    {
        if (mt_rand(0, 10) < 5) {
            $depute = $this->connection->fetchAssociative(
                'SELECT d.firstname, d.lastname
                 FROM depute d
                 WHERE d.dpt_slug IS NOT NULL
                   AND EXISTS (SELECT 1 FROM mandat m
                               WHERE m.depute_id = d.id
                                 AND m.legislature = :legislature
                                 AND m.date_fin IS NULL)
                 ORDER BY RAND() LIMIT 1',
                ['legislature' => Legislature::COURANTE],
            );

            if ($depute !== false) {
                return $depute['firstname'] . ' ' . $depute['lastname'];
            }
        }

        $groupe = $this->connection->fetchAssociative(
            'SELECT libelle, libelle_abrev FROM groupe
             WHERE legislature = :legislature AND date_fin IS NULL
             ORDER BY RAND() LIMIT 1',
            ['legislature' => Legislature::COURANTE],
        );

        return $groupe === false ? 'Assemblée nationale' : $groupe['libelle'] . ' (' . $groupe['libelle_abrev'] . ')';
    }

    /**
     * Groupes en activité et leur effectif, du plus grand au plus petit.
     * Les non-inscrits en font partie : ils occupent des sièges dans l'hémicycle.
     *
     * @return list<array<string, mixed>>
     */
    private function groupesHemicycle(int $legislature): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT g.libelle, g.libelle_abrev, g.legislature, COUNT(d.id) AS effectif,
                    ' . CouleurGroupe::SQL . ' AS couleur
             FROM groupe g
             LEFT JOIN depute d ON d.groupe_id = g.id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL
             GROUP BY g.id
             HAVING effectif > 0
             ORDER BY effectif DESC, g.libelle',
            ['legislature' => $legislature],
        );
    }

    /**
     * Effectif cumulé de chaque bloc politique.
     *
     * @param list<array<string, mixed>> $groupes
     *
     * @return array<string, int>
     */
    private function blocs(array $groupes): array
    {
        $effectifs = array_column($groupes, 'effectif', 'libelle_abrev');

        return array_map(
            static fn (array $abrevs) => (int) array_sum(array_intersect_key($effectifs, array_flip($abrevs))),
            self::BLOCS,
        );
    }

    /**
     * Découpe de l'hémicycle en arcs, de la gauche à la droite de l'hémicycle.
     *
     * Le site d'origine dessine ce demi-cercle avec Chart.js ; le tracé est ici
     * calculé côté serveur et rendu en SVG, ce qui évite de charger une
     * bibliothèque de graphiques sur la page la plus visitée.
     *
     * @param list<array<string, mixed>> $groupes
     *
     * @return list<array<string, mixed>> chaque entrée porte le tracé SVG de son arc
     */
    private function hemicycle(array $groupes): array
    {
        $rang = array_flip(self::ORDRE_HEMICYCLE);
        $inconnu = count($rang);
        usort($groupes, static fn (array $a, array $b) => ($rang[$a['libelle_abrev']] ?? $inconnu) <=> ($rang[$b['libelle_abrev']] ?? $inconnu));

        $total = max(1, array_sum(array_column($groupes, 'effectif')));
        $angle = 0.0;

        foreach ($groupes as &$groupe) {
            $ouverture = (int) $groupe['effectif'] / $total * M_PI;
            $groupe['path'] = $this->arc($angle, $angle + $ouverture);
            $angle += $ouverture;
        }

        return $groupes;
    }

    /**
     * Tracé d'un secteur d'anneau sur un demi-cercle de rayon 100, centré en
     * (100, 100), l'angle 0 pointant vers la gauche de l'hémicycle.
     */
    private function arc(float $debut, float $fin): string
    {
        $exterieur = 100.0;
        $interieur = 55.0;

        $point = static fn (float $rayon, float $angle): string => sprintf(
            '%.2f %.2f',
            100 - $rayon * cos($angle),
            100 - $rayon * sin($angle),
        );

        return sprintf(
            'M %s A %d %d 0 0 1 %s L %s A %d %d 0 0 0 %s Z',
            $point($exterieur, $debut),
            $exterieur,
            $exterieur,
            $point($exterieur, $fin),
            $point($interieur, $fin),
            $interieur,
            $interieur,
            $point($interieur, $debut),
        );
    }

    /**
     * Derniers votes décryptés par la rédaction.
     *
     * @return list<array<string, mixed>>
     */
    private function derniersDecryptages(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT dcr.title, dcr.legislature, dcr.vote_numero,
                    s.date_scrutin, s.sort_code,
                    c.name AS categorie_name, l.name AS lecture_name
             FROM decryptage dcr
             JOIN scrutin s ON s.id = dcr.scrutin_id
             LEFT JOIN categorie c ON c.id = dcr.categorie_id
             LEFT JOIN lecture l ON l.id = dcr.lecture_id
             WHERE dcr.state = :published
             ORDER BY dcr.legislature DESC, s.date_scrutin DESC, dcr.vote_numero DESC
             LIMIT 5',
            ['published' => 'published'],
        );

        foreach ($rows as &$row) {
            $row['date_abregee'] = $this->dateAbregee($row['date_scrutin']);
        }

        return $rows;
    }

    /**
     * Dernières explications de vote publiées par les députés.
     *
     * L'application d'origine en tire trois au hasard parmi les quinze plus
     * récentes ; on prend ici les trois plus récentes, pour que la page reste
     * identique d'un appel à l'autre et donc réellement cachable.
     *
     * @return list<array<string, mixed>>
     */
    private function dernieresExplications(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT e.texte, d.firstname, d.lastname, d.slug, d.dpt_slug,
                    g.libelle_abrev AS groupe_abrev, g.legislature AS groupe_legislature,
                    ' . CouleurGroupe::SQL . ' AS groupe_couleur,
                    dcr.title AS decryptage_title, dcr.legislature, dcr.vote_numero,
                    v.position
             FROM explication e
             JOIN depute d ON d.id = e.depute_id
             JOIN decryptage dcr ON dcr.scrutin_id = e.scrutin_id AND dcr.state = :published
             LEFT JOIN groupe g ON g.id = d.groupe_id
             LEFT JOIN vote v ON v.depute_id = e.depute_id AND v.scrutin_id = e.scrutin_id
                              AND v.vote_type = :officiel
             WHERE e.publiee = 1
             ORDER BY e.modified_at DESC, e.created_at DESC
             LIMIT 3',
            ['published' => 'published', 'officiel' => 'decompteNominatif'],
        );
    }

    private function dateAbregee(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $dt = new \DateTimeImmutable($date);

        return sprintf('%s %s %s', $dt->format('d'), self::MOIS_ABREGES[(int) $dt->format('n')], $dt->format('Y'));
    }
}
