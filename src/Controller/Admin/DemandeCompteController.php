<?php

namespace App\Controller\Admin;

use App\Entity\DemandeCompteDepute;
use App\Entity\Depute;
use App\Entity\Utilisateur;
use App\Repository\DemandeCompteDeputeRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Traitement des demandes de compte des députés (`/demande-compte-depute`).
 *
 * L'application d'origine n'avait pas d'écran d'administration : le député
 * recevait par courriel un lien d'activation et créait lui-même son compte. Ce
 * portage n'envoie pas de courriel — la validation passe donc par un
 * administrateur, qui relit la demande et ouvre le compte. C'est ici que se
 * reporte le contrôle par l'adresse institutionnelle : les identifiants sont
 * transmis à l'adresse `@assemblee-nationale.fr` que le député a saisie.
 *
 * Réservé aux administrateurs — ouvrir un compte de connexion est un acte plus
 * sensible que rédiger un décryptage. La création réutilise la mécanique de
 * `app:utilisateur:creer` : rattachement à un député (donc ROLE_DEPUTE
 * exclusif, voir Utilisateur::getRoles), mot de passe haché par le vérifieur
 * `auto`.
 */
#[Route('/admin/demandes-comptes')]
#[IsGranted(Utilisateur::ROLE_ADMIN)]
class DemandeCompteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DemandeCompteDeputeRepository $demandes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    #[Route('', name: 'admin_demande_compte_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/demande_compte/index.html.twig', [
            'demandes' => $this->demandes->enAttente(),
        ]);
    }

    #[Route('/{id}/approuver', name: 'admin_demande_compte_approuver', methods: ['POST'])]
    public function approuver(DemandeCompteDepute $demande, Request $requete): Response
    {
        if (!$this->jetonValide($demande, $requete)) {
            return $this->redirectToRoute('admin_demande_compte_index');
        }

        $depute = $demande->getDepute();

        // Un compte a pu être ouvert entre-temps (commande, autre demande) : on
        // ne fabrique pas de doublon, on classe la demande.
        if ($this->utilisateurs->findOneBy(['depute' => $depute]) !== null) {
            $this->classer($demande, DemandeCompteDepute::REFUSEE);
            $this->addFlash('erreur', sprintf(
                '%s %s a déjà un compte : la demande a été classée sans en créer un second.',
                $depute->getFirstname(),
                $depute->getLastname(),
            ));

            return $this->redirectToRoute('admin_demande_compte_index');
        }

        $identifiant = $this->identifiantLibre($depute);
        $motDePasse = $this->motDePasseProvisoire();

        $utilisateur = new Utilisateur();
        $utilisateur->setIdentifiant($identifiant);
        $utilisateur->setNom($depute->getFirstname() . ' ' . $depute->getLastname());
        $utilisateur->setEmail($demande->getEmail());
        // Le rattachement au député porte le rôle : ROLE_DEPUTE, jamais la
        // rédaction (Utilisateur::getRoles impose l'exclusion).
        $utilisateur->setDepute($depute);
        $utilisateur->setRoles([]);
        $utilisateur->setPassword($this->hacheur->hashPassword($utilisateur, $motDePasse));

        $this->entityManager->persist($utilisateur);
        $this->classer($demande, DemandeCompteDepute::APPROUVEE);

        // Le mot de passe en clair n'est montré qu'ici, une fois : l'administrateur
        // le transmet au député à son adresse institutionnelle, puis il disparaît.
        $this->addFlash('succes', sprintf(
            'Compte ouvert pour %s %s. À transmettre à %s — identifiant : %s · mot de passe provisoire : %s. Ce mot de passe ne sera plus affiché.',
            $depute->getFirstname(),
            $depute->getLastname(),
            $demande->getEmail(),
            $identifiant,
            $motDePasse,
        ));

        return $this->redirectToRoute('admin_demande_compte_index');
    }

    #[Route('/{id}/refuser', name: 'admin_demande_compte_refuser', methods: ['POST'])]
    public function refuser(DemandeCompteDepute $demande, Request $requete): Response
    {
        if (!$this->jetonValide($demande, $requete)) {
            return $this->redirectToRoute('admin_demande_compte_index');
        }

        $depute = $demande->getDepute();
        $this->classer($demande, DemandeCompteDepute::REFUSEE);

        $this->addFlash('succes', sprintf(
            'Demande de %s %s refusée.',
            $depute->getFirstname(),
            $depute->getLastname(),
        ));

        return $this->redirectToRoute('admin_demande_compte_index');
    }

    /**
     * Jeton CSRF valide et demande encore à traiter — sinon un message et un
     * retour à la liste. Le jeton est propre à la demande, pour qu'un bouton
     * copié ne serve pas sur une autre.
     */
    private function jetonValide(DemandeCompteDepute $demande, Request $requete): bool
    {
        if (!$this->isCsrfTokenValid('traiter_demande_' . $demande->getId(), (string) $requete->request->get('_token'))) {
            $this->addFlash('erreur', 'Jeton de sécurité invalide, demande non traitée.');

            return false;
        }

        if (!$demande->estEnAttente()) {
            $this->addFlash('erreur', 'Cette demande a déjà été traitée.');

            return false;
        }

        return true;
    }

    private function classer(DemandeCompteDepute $demande, string $etat): void
    {
        $demande->setEtat($etat);
        $demande->setTraiteeLe(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Un identifiant de connexion libre, dérivé du slug du député (« julien-guibert »)
     * et suffixé s'il est déjà pris.
     */
    private function identifiantLibre(Depute $depute): string
    {
        $base = $depute->getSlug()
            ?: (new AsciiSlugger())->slug($depute->getFirstname() . ' ' . $depute->getLastname())->lower()->toString();

        $identifiant = $base;
        $suffixe = 1;

        while ($this->utilisateurs->findOneBy(['identifiant' => $identifiant]) !== null) {
            $identifiant = $base . '-' . (++$suffixe);
        }

        return $identifiant;
    }

    /**
     * Mot de passe provisoire fort (72 bits), au-delà du minimum de 12 caractères
     * qu'exige `app:utilisateur:creer`. Le député le changera — la page
     * « mon compte » relève du chantier des comptes lecteurs, à venir.
     */
    private function motDePasseProvisoire(): string
    {
        return bin2hex(random_bytes(9));
    }
}
