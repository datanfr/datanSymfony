<?php

namespace App\Controller;

use App\Legislature;
use App\Repository\ResultatCirconscriptionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Brique de diffusion embarquée par des sites tiers, portée du contrôleur Iframe
 * de l'application d'origine (Iframe.php, vues iframe/**).
 *
 * Deux contraintes tiennent lieu de contrat : l'**adresse** (indexée, citée dans
 * des intégrations existantes) et l'**embarquabilité**. La réponse ne porte donc
 * aucun `X-Frame-Options` ni `Content-Security-Policy: frame-ancestors` — l'appli
 * n'en pose aucun globalement, et ce contrôleur n'en ajoute pas. Le gabarit est
 * autonome (ni barre de navigation, ni pied de page de site), et n'embarque pas
 * le pistage (Matomo, GTM) ni la bannière de consentement (tarteaucitron) du
 * site hôte : les injecter chez un tiers serait un défaut, pas une parité.
 *
 * Le contenu reprend les blocs de la fiche de député tels que ce portage les
 * rend aujourd'hui (positions, derniers votes, élection, comportement). Les
 * carrousels et les graphiques du comportement politique de datan.fr relèvent
 * d'un chantier de statistiques que la fiche elle-même n'a pas encore ; l'écart
 * est le même ici que sur /deputes, et signalé.
 */
class IframeController extends AbstractController
{
    private const OFFICIAL = 'decompteNominatif';

    private const SOLENNEL = 'SPS';

    /**
     * Cache de trois jours, comme le `$this->output->cache("4320")` (minutes) de
     * l'application d'origine : une page très rappelée par des tiers, dont les
     * données ne bougent qu'au rythme des scrutins.
     */
    private const CACHE_TTL = 259200;

    /** Blocs affichables, dans l'ordre par défaut de l'origine (hors explication et questions, non portés). */
    private const BLOCS = ['positions-importantes', 'derniers-votes', 'election', 'comportement-politique'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ResultatCirconscriptionRepository $resultatsCirconscription,
    ) {
    }

    /**
     * Page de démonstration : un exemple d'intégration, comme iframe/index.php.
     * Autonome et sans pistage, elle aussi.
     */
    #[Route('/iframe', name: 'iframe_index', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('iframe/index.html.twig');
        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    #[Route('/iframe/depute/{slug}', name: 'iframe_depute', requirements: ['slug' => '[a-z0-9\-]+'], methods: ['GET'])]
    public function depute(string $slug, Request $request): Response
    {
        // Même parade que DeputeController (jamais modifié ici) : un slug n'est
        // pas unique — un sénateur homonyme sans page peut le partager —, la ligne
        // qui porte un dpt_slug (donc une fiche) l'emporte, et sans dpt_slug il
        // n'y a pas de page à embarquer, donc 404.
        $depute = $this->connection->fetchAssociative(
            'SELECT d.id, d.mp_id, d.firstname, d.lastname, d.slug, d.dpt_slug, d.civilite,
                    d.departement_nom, d.departement_code, d.circonscription, d.region,
                    g.id AS groupe_id, g.libelle AS groupe_libelle, g.libelle_abrev AS groupe_abrev,
                    g.couleur AS groupe_couleur
             FROM depute d
             LEFT JOIN groupe g ON g.id = d.groupe_id
             WHERE d.slug = :slug
             ORDER BY (d.dpt_slug IS NULL), d.id
             LIMIT 1',
            ['slug' => $slug],
        );

        if ($depute === false || $depute['dpt_slug'] === null) {
            throw $this->createNotFoundException('Député introuvable ou sans fiche.');
        }

        $deputeId = (int) $depute['id'];
        $groupeId = $depute['groupe_id'] !== null ? (int) $depute['groupe_id'] : null;
        $mandats = $this->mandats($deputeId);

        // Le site ne diffuse que la 14e législature et au-delà (Iframe.php). Nos
        // députés à fiche siègent tous à partir de la 14e ; la garde reste posée.
        if ($mandats === [] || (int) $mandats[0]['legislature'] < Legislature::PREMIERE) {
            throw $this->createNotFoundException('Législature non diffusée.');
        }

        $blocs = $this->blocsDemandes($request->query->get('categories'));

        $election = \in_array('election', $blocs, true)
            ? $this->resultatsCirconscription->pourDepute(
                $mandats[0]['departement_code'],
                $mandats[0]['circonscription'] !== null ? (int) $mandats[0]['circonscription'] : null,
                (int) $mandats[0]['legislature'],
                (string) $depute['lastname'],
            )
            : null;

        $premierePersonne = $request->query->get('first-person') === 'true';
        $femme = $depute['civilite'] === 'Mme';

        $response = $this->render('iframe/depute.html.twig', [
            'depute' => $depute,
            'blocs' => $blocs,
            'positions_cles' => \in_array('positions-importantes', $blocs, true) ? $this->positionsCles($deputeId) : [],
            'derniers_votes' => \in_array('derniers-votes', $blocs, true) ? $this->derniersVotes($deputeId) : [],
            'participation' => \in_array('comportement-politique', $blocs, true) ? $this->participation($deputeId) : null,
            'loyaute' => \in_array('comportement-politique', $blocs, true) && $groupeId !== null ? $this->loyaute($deputeId, $groupeId) : null,
            'election' => $election,
            // Titres : « la députée » / « le député » (gender() de l'origine), et
            // « e » d'accord. La première personne (?first-person=true) bascule
            // « Son » en « Mon », comme les partials _first_person du legacy.
            'depute_mot' => $femme ? 'députée' : 'député',
            'accord' => $femme ? 'e' : '',
            'premiere_personne' => $premierePersonne,
            'possessif' => $premierePersonne ? 'Mon' : 'Son',
            'possessif_pluriel' => $premierePersonne ? 'Mes' : 'Ses',
            // ?main-title=hide et ?secondary-title=hide, comme l'origine.
            'titre_principal_visible' => $request->query->get('main-title') !== 'hide',
            'sous_titres_visibles' => $request->query->get('secondary-title') !== 'hide',
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Blocs à afficher : la liste `?categories=` filtrée sur ceux que nous
     * portons, dans l'ordre demandé, ou l'ordre par défaut de l'origine. Les
     * sous-catégories pointées (`comportement-politique.participation-votes`)
     * sont ramenées à leur bloc principal.
     *
     * @return list<string>
     */
    private function blocsDemandes(?string $categories): array
    {
        if ($categories === null || $categories === '') {
            return self::BLOCS;
        }

        $demandes = array_map(static fn (string $c): string => explode('.', trim($c))[0], explode(',', $categories));
        $retenus = array_values(array_unique(array_filter(
            $demandes,
            static fn (string $c): bool => \in_array($c, self::BLOCS, true),
        )));

        return $retenus !== [] ? $retenus : self::BLOCS;
    }

    /**
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
     * Positions du député sur les votes décryptés. Copie de la lecture de
     * DeputeController : la fiche et l'iframe montrent le même bloc, mais le
     * contrat interdit de toucher au contrôleur des députés — d'où la duplication.
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

    /**
     * @return list<array<string, mixed>>
     */
    private function derniersVotes(int $deputeId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.legislature, s.numero, s.titre, s.sort_code, v.position, v.scrutin_date,
                    dcr.title AS decryptage_title
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             LEFT JOIN decryptage dcr ON dcr.scrutin_id = s.id AND dcr.state = :published
             WHERE v.depute_id = :depute AND v.vote_type = :type
             ORDER BY v.scrutin_date DESC
             LIMIT 10',
            ['depute' => $deputeId, 'type' => self::OFFICIAL, 'published' => 'published'],
        );
    }

    /**
     * @return array{exprimes: int, total: int, taux: int|null}
     */
    private function participation(int $deputeId): array
    {
        $fenetre = $this->connection->fetchAssociative(
            'SELECT MIN(v.scrutin_date) AS debut, MAX(v.scrutin_date) AS fin
             FROM vote v WHERE v.depute_id = :depute',
            ['depute' => $deputeId],
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
               AND v.position IN (\'pour\', \'contre\', \'abstention\')',
            ['depute' => $deputeId, 'type' => self::OFFICIAL, 'solennel' => self::SOLENNEL],
        );

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM scrutin s
             WHERE s.code_type_vote = :solennel AND s.date_scrutin BETWEEN :debut AND :fin',
            ['solennel' => self::SOLENNEL, 'debut' => $fenetre['debut'], 'fin' => $fenetre['fin']],
        );

        return [
            'exprimes' => $exprimes,
            'total' => $total,
            'taux' => $total > 0 ? (int) round($exprimes / $total * 100) : null,
        ];
    }

    /**
     * @return array{conformes: int, total: int, taux: int|null}
     */
    private function loyaute(int $deputeId, int $groupeId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(v.position = vg.position_majoritaire) AS conformes
             FROM vote v
             JOIN vote_groupe vg ON vg.scrutin_id = v.scrutin_id AND vg.groupe_id = :groupe
             JOIN scrutin s ON s.id = v.scrutin_id
             WHERE v.depute_id = :depute AND v.vote_type = :type
               AND v.position IN (\'pour\', \'contre\', \'abstention\')',
            ['depute' => $deputeId, 'groupe' => $groupeId, 'type' => self::OFFICIAL],
        ) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $conformes = (int) ($row['conformes'] ?? 0);

        return [
            'conformes' => $conformes,
            'total' => $total,
            'taux' => $total > 0 ? (int) round($conformes / $total * 100) : null,
        ];
    }
}
