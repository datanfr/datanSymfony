<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Legislature;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableaux d'analyse « réseaux sociaux » de l'espace de rédaction
 * (`Admin::socialmedia`).
 *
 * Ce sont des vues en lecture seule qui aident la rédaction à repérer les
 * mouvements de députés (entrées, sorties, changements de groupe) pour tenir à
 * jour, à la main et hors de la base ouverte, les photos et les réseaux sociaux.
 * Le legacy ne pose ici aucune garde d'administrateur : tout membre de la
 * rédaction y accède.
 *
 * **Deux tableaux du legacy ne sont pas reproductibles en l'état** et le disent
 * franchement au lieu d'afficher du vide trompeur :
 *  - « Postes Assemblée » lit les mandats en organe (commissions), que notre
 *    schéma ne porte pas encore (pas de table de mandats secondaires ni
 *    d'organes) ;
 *  - « Comptes X » lit les pseudos Twitter des députés, une donnée que Datan
 *    tient à la main et qui n'est pas encore importée (cf. CLAUDE.md, réseaux
 *    sociaux à la charge de Datan).
 */
#[Route('/admin/socialmedia')]
#[IsGranted(Utilisateur::ROLE_REDACTEUR)]
class SocialmediaController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Historique des groupes d'un député (`socialmedia/historique/{mpId}`).
     *
     * Déclaré avant la route générique, et plus prioritaire, pour que
     * `historique/PAxxxx` ne soit pas avalé par le paramètre `{page}`.
     */
    #[Route('/historique/{mpId}', name: 'admin_socialmedia_historique', requirements: ['mpId' => '[A-Za-z0-9]+'], priority: 10, methods: ['GET'])]
    public function historiqueDepute(string $mpId): Response
    {
        $depute = $this->connection->fetchAssociative(
            'SELECT firstname, lastname, mp_id AS mpId FROM depute WHERE mp_id = :mpId',
            ['mpId' => $mpId],
        );

        if ($depute === false) {
            throw $this->createNotFoundException('Député inconnu.');
        }

        // Historique des rattachements de groupe depuis la 15e législature, comme
        // le legacy (`mandat_groupe` → notre `fonction_groupe`, `organes` → `groupe`).
        $historique = $this->connection->fetchAllAssociative(
            'SELECT g.libelle AS groupe, fg.code_qualite AS codeQualite,
                    fg.date_debut AS dateDebut, fg.date_fin AS dateFin
             FROM fonction_groupe fg
             JOIN groupe g ON g.id = fg.groupe_id
             WHERE fg.depute_id = (SELECT id FROM depute WHERE mp_id = :mpId)
               AND g.legislature >= 15
             ORDER BY fg.date_debut DESC',
            ['mpId' => $mpId],
        );

        return $this->render('admin/socialmedia/historique.html.twig', [
            'depute' => $depute,
            'historique' => $historique,
        ]);
    }

    #[Route('/{page}', name: 'admin_socialmedia', requirements: ['page' => 'deputes_entrants|deputes_sortants|postes_assemblee|groupes_entrants|historique|x'], methods: ['GET'])]
    public function page(string $page): Response
    {
        return match ($page) {
            'deputes_entrants' => $this->tableau('Députés entrants', $this->deputesEntrants(), [
                'prenom' => 'Prénom', 'nom' => 'Nom', 'mpId' => 'mpId',
                'dateDebut' => 'Prise de fonction', 'dateFin' => 'Fin',
            ]),
            'deputes_sortants' => $this->tableau('Députés sortants', $this->deputesSortants(), [
                'prenom' => 'Prénom', 'nom' => 'Nom', 'mpId' => 'mpId',
                'dateDebut' => 'Début', 'dateFin' => 'Fin',
            ]),
            'groupes_entrants' => $this->tableau('Nouveaux membres de groupe', $this->groupesEntrants(), [
                'prenom' => 'Prénom', 'nom' => 'Nom', 'mpId' => 'mpId',
                'groupe' => 'Groupe', 'codeQualite' => 'Qualité',
                'dateDebut' => 'Début', 'dateFin' => 'Fin',
            ]),
            'historique' => $this->historiqueListe(),
            // Non reproductibles : on l'explique au lieu d'afficher un tableau vide.
            'postes_assemblee' => $this->indisponible(
                'Postes Assemblée',
                "Les mandats en organe (commissions, délégations) ne sont pas encore portés dans ce schéma : cette table reste à faire.",
            ),
            'x' => $this->indisponible(
                'Comptes X des députés',
                "Les réseaux sociaux des députés sont une donnée que Datan tient à la main, pas encore importée. Cette table sera alimentée quand elle le sera.",
            ),
            default => throw $this->createNotFoundException(),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deputesEntrants(): array
    {
        // Notre table `mandat` est le mandat parlementaire principal (le
        // « membre » du legacy) : pas de filtre `codeQualite` à poser.
        return $this->connection->fetchAllAssociative(
            'SELECT d.firstname AS prenom, d.lastname AS nom, d.mp_id AS mpId,
                    m.date_debut AS dateDebut, m.date_fin AS dateFin
             FROM mandat m
             JOIN depute d ON d.id = m.depute_id
             WHERE m.legislature = :leg
             ORDER BY m.date_debut DESC',
            ['leg' => Legislature::COURANTE],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deputesSortants(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.firstname AS prenom, d.lastname AS nom, d.mp_id AS mpId,
                    m.date_debut AS dateDebut, m.date_fin AS dateFin
             FROM mandat m
             JOIN depute d ON d.id = m.depute_id
             WHERE m.legislature = :leg AND m.date_fin IS NOT NULL
             ORDER BY m.date_fin DESC',
            ['leg' => Legislature::COURANTE],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupesEntrants(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT d.firstname AS prenom, d.lastname AS nom, d.mp_id AS mpId,
                    g.libelle AS groupe, fg.code_qualite AS codeQualite,
                    fg.date_debut AS dateDebut, fg.date_fin AS dateFin
             FROM fonction_groupe fg
             JOIN depute d ON d.id = fg.depute_id
             JOIN groupe g ON g.id = fg.groupe_id
             WHERE g.legislature = :leg
             ORDER BY fg.date_debut DESC',
            ['leg' => Legislature::COURANTE],
        );
    }

    private function historiqueListe(): Response
    {
        // Les députés ayant exercé un mandat sous la législature en cours, chacun
        // menant à son historique de groupes (comme la liste du legacy).
        $deputes = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT d.firstname AS prenom, d.lastname AS nom, d.mp_id AS mpId
             FROM depute d
             JOIN mandat m ON m.depute_id = d.id AND m.legislature = :leg
             ORDER BY d.lastname ASC, d.firstname ASC',
            ['leg' => Legislature::COURANTE],
        );

        return $this->render('admin/socialmedia/liste.html.twig', [
            'titre' => 'Historique des groupes par député',
            'deputes' => $deputes,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $lignes
     * @param array<string, string>      $colonnes
     */
    private function tableau(string $titre, array $lignes, array $colonnes): Response
    {
        return $this->render('admin/socialmedia/table.html.twig', [
            'titre' => $titre,
            'colonnes' => $colonnes,
            'lignes' => $lignes,
        ]);
    }

    private function indisponible(string $titre, string $message): Response
    {
        return $this->render('admin/socialmedia/indisponible.html.twig', [
            'titre' => $titre,
            'message' => $message,
        ]);
    }
}
