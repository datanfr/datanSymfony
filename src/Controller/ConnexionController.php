<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class ConnexionController extends AbstractController
{
    #[Route('/connexion', name: 'connexion', methods: ['GET', 'POST'])]
    public function connexion(AuthenticationUtils $authentification): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('apres_connexion');
        }

        return $this->render('connexion/index.html.twig', [
            'dernier_identifiant' => $authentification->getLastUsername(),
            'erreur' => $authentification->getLastAuthenticationError(),
        ]);
    }

    /**
     * Aiguillage vers l'espace du compte connecté.
     *
     * Un député et un rédacteur n'atterrissent pas au même endroit, et rien ne
     * le dit avant l'authentification : envoyer tout le monde sur les
     * décryptages vaudrait un refus d'accès à la moitié des comptes.
     */
    #[Route('/apres-connexion', name: 'apres_connexion', methods: ['GET'])]
    public function apresConnexion(): Response
    {
        return $this->redirectToRoute(
            $this->isGranted(Utilisateur::ROLE_DEPUTE) ? 'dashboard' : 'admin_decryptage_index',
        );
    }

    /**
     * Interceptée par le pare-feu ; cette méthode n'est jamais exécutée.
     */
    #[Route('/deconnexion', name: 'deconnexion', methods: ['GET'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Déconnexion prise en charge par le pare-feu de sécurité.');
    }
}
