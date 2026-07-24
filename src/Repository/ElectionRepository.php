<?php

namespace App\Repository;

use App\Entity\Election;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture du catalogue des élections, en DBAL et en tableaux associatifs.
 *
 * Le SQL des pages de lecture vit d'ordinaire dans le contrôleur, comme le veut
 * la convention du projet. Il est ici parce que les mêmes requêtes servent
 * `/elections` et `/elections/{scrutin}`, et que l'état d'avancement d'un
 * scrutin est une règle qu'on ne veut écrite qu'une fois.
 *
 * @extends ServiceEntityRepository<Election>
 */
class ElectionRepository extends ServiceEntityRepository
{
    /** Premier tour à venir : le scrutin n'a pas commencé. */
    public const ATTENDU = 0;

    /** Premier tour dépouillé, second tour à venir. */
    public const PREMIER_TOUR = 1;

    /** Scrutin achevé — second tour dépouillé, ou tour unique. */
    public const ACHEVE = 2;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Election::class);
    }

    /**
     * Le catalogue, du plus récent au plus ancien, avec le décompte des
     * candidatures publiées.
     *
     * L'ordre est « année décroissante, puis identifiant croissant » : dans une
     * même année, le site montre l'européenne avant la législative de 2024 et la
     * présidentielle avant la législative de 2022 — l'ordre d'insertion du
     * catalogue, non celui des dates, qui placerait la législative (plus
     * tardive) en tête.
     *
     * @return list<array<string, mixed>>
     */
    public function toutes(): array
    {
        $elections = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT e.id, e.identifiant, e.slug, e.libelle, e.libelle_abrege, e.annee,
                    e.date_tour1, e.date_tour2, e.candidats, e.url_resultats,
                    COUNT(c.id) AS candidats_n,
                    SUM(c.second_tour = 1) AS second_tour_n,
                    SUM(c.elu = 1) AS elus_n
             FROM election e
             LEFT JOIN candidature c ON c.election_id = e.id AND c.visible = 1 AND c.candidat = 1
             GROUP BY e.id
             ORDER BY e.annee DESC, e.id ASC',
        );

        foreach ($elections as &$election) {
            $election['etat'] = $this->etat($election['date_tour1'], $election['date_tour2']);
        }

        return $elections;
    }

    /** @return array<string, mixed>|null */
    public function parSlug(string $slug): ?array
    {
        $election = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT id, identifiant, slug, libelle, libelle_abrege, annee,
                    date_tour1, date_tour2, candidats, url_resultats
             FROM election WHERE slug = ?',
            [$slug],
        );

        if ($election === false) {
            return null;
        }

        $election['etat'] = $this->etat($election['date_tour1'], $election['date_tour2']);

        return $election;
    }

    /**
     * État d'avancement du scrutin, déduit de ses dates.
     *
     * L'application d'origine le donne par un `switch` sur l'identifiant du
     * scrutin (`Elections_model::get_election_state()`), qu'il faut rallonger à
     * chaque élection. Les dates disent la même chose et n'ont pas à être
     * tenues : les six scrutins connus sont achevés, et le prochain le deviendra
     * sans qu'on y touche.
     *
     * Un scrutin à tour unique — les européennes — n'a pas de `date_tour2` :
     * c'est le premier tour qui l'achève.
     */
    private function etat(?string $tour1, ?string $tour2): int
    {
        $aujourdhui = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $dernierTour = $tour2 ?? $tour1;

        if ($dernierTour !== null && $dernierTour < $aujourdhui) {
            return self::ACHEVE;
        }

        if ($tour1 !== null && $tour1 < $aujourdhui) {
            return self::PREMIER_TOUR;
        }

        return self::ATTENDU;
    }
}
