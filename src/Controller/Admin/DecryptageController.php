<?php

namespace App\Controller\Admin;

use App\Entity\Decryptage;
use App\Entity\Utilisateur;
use App\Enum\DecryptageState;
use App\Form\DecryptageType;
use App\Repository\DecryptageRepository;
use App\Repository\ScrutinRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Rédaction des décryptages de votes.
 *
 * C'est la raison d'être du site : le décryptage est la seule donnée que Datan
 * produit au lieu de la recevoir. Les règles reprennent celles de
 * l'application d'origine (`Admin::create_vote` / `modify_vote` /
 * `delete_vote`) : un rédacteur écrit et publie ; une fois publié, seul un
 * administrateur peut reprendre le texte ou le supprimer.
 */
#[Route('/admin/decryptages')]
#[IsGranted(Utilisateur::ROLE_REDACTEUR)]
class DecryptageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DecryptageRepository $decryptages,
        private readonly ScrutinRepository $scrutins,
    ) {
    }

    #[Route('', name: 'admin_decryptage_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/decryptage/index.html.twig', [
            'decryptages' => $this->decryptages->createQueryBuilder('d')
                ->leftJoin('d.categorie', 'c')->addSelect('c')
                ->leftJoin('d.auteur', 'a')->addSelect('a')
                ->leftJoin('d.scrutin', 's')->addSelect('s')
                ->orderBy('d.createdAt', 'DESC')
                ->getQuery()
                ->getResult(),
        ]);
    }

    #[Route('/nouveau', name: 'admin_decryptage_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $decryptage = new Decryptage();
        $decryptage->setState(DecryptageState::Draft);

        $formulaire = $this->createForm(DecryptageType::class, $decryptage, [
            'creation' => true,
            'peut_publier' => false,
        ]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $existant = $this->decryptages->findOneBy([
                'legislature' => $decryptage->getLegislature(),
                'voteNumero' => $decryptage->getVoteNumero(),
            ]);

            if ($existant !== null) {
                $this->addFlash('erreur', sprintf(
                    'Le scrutin n° %d de la %de législature est déjà décrypté : « %s ».',
                    $decryptage->getVoteNumero(),
                    $decryptage->getLegislature(),
                    $existant->getTitle(),
                ));

                return $this->redirectToRoute('admin_decryptage_modifier', ['id' => $existant->getId()]);
            }

            $scrutin = $this->scrutins->findOneBy([
                'legislature' => $decryptage->getLegislature(),
                'numero' => $decryptage->getVoteNumero(),
            ]);

            if ($scrutin === null) {
                $this->addFlash('erreur', sprintf(
                    'Aucun scrutin n° %d en %de législature. Vérifiez le numéro.',
                    $decryptage->getVoteNumero(),
                    $decryptage->getLegislature(),
                ));
            } else {
                $decryptage->setScrutin($scrutin);
                $decryptage->setVoteId($scrutin->getUid());
                $decryptage->setSlug($this->slug($decryptage->getTitle()));
                $decryptage->setCreatedAt(new \DateTimeImmutable());
                $decryptage->setAuteur($this->utilisateur());

                $this->entityManager->persist($decryptage);
                $this->entityManager->flush();

                $this->addFlash('succes', 'Décryptage créé. Il reste en brouillon tant que vous ne le publiez pas.');

                return $this->redirectToRoute('admin_decryptage_modifier', ['id' => $decryptage->getId()]);
            }
        }

        return $this->render('admin/decryptage/form.html.twig', [
            'formulaire' => $formulaire,
            'decryptage' => null,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_decryptage_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Decryptage $decryptage): Response
    {
        if (!$this->peutModifier($decryptage)) {
            $this->addFlash('erreur', 'Ce décryptage est publié : seul un administrateur peut le reprendre.');

            return $this->redirectToRoute('admin_decryptage_index');
        }

        $formulaire = $this->createForm(DecryptageType::class, $decryptage);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $decryptage->setSlug($this->slug($decryptage->getTitle()));
            $decryptage->setModifiedAt(new \DateTimeImmutable());
            $decryptage->setModifiePar($this->utilisateur());

            $this->entityManager->flush();

            $this->addFlash('succes', $decryptage->getState() === DecryptageState::Published
                ? 'Décryptage enregistré et publié.'
                : 'Décryptage enregistré en brouillon.');

            return $this->redirectToRoute('admin_decryptage_index');
        }

        return $this->render('admin/decryptage/form.html.twig', [
            'formulaire' => $formulaire,
            'decryptage' => $decryptage,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_decryptage_supprimer', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADMIN)]
    public function supprimer(Request $request, Decryptage $decryptage): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('supprimer_decryptage_' . $decryptage->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('erreur', 'Jeton de sécurité invalide, suppression annulée.');

                return $this->redirectToRoute('admin_decryptage_index');
            }

            $titre = $decryptage->getTitle();
            $this->entityManager->remove($decryptage);
            $this->entityManager->flush();

            $this->addFlash('succes', sprintf('Décryptage « %s » supprimé.', $titre));

            return $this->redirectToRoute('admin_decryptage_index');
        }

        return $this->render('admin/decryptage/supprimer.html.twig', ['decryptage' => $decryptage]);
    }

    /**
     * Un décryptage publié est figé pour son rédacteur : le corriger relève de
     * l'administrateur, comme dans l'application d'origine.
     */
    private function peutModifier(Decryptage $decryptage): bool
    {
        return $decryptage->getState() !== DecryptageState::Published
            || $this->isGranted(Utilisateur::ROLE_ADMIN);
    }

    private function utilisateur(): ?Utilisateur
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    /**
     * Le slug détermine l'adresse publique du décryptage.
     *
     * Le titre est nettoyé avant d'être translittéré : le slugger refuse une
     * chaîne mal encodée, et un client qui enverrait autre chose que de l'UTF-8
     * ne doit pas provoquer une erreur serveur.
     */
    private function slug(?string $titre): string
    {
        $titre = mb_convert_encoding((string) $titre, 'UTF-8', 'UTF-8');

        return (new AsciiSlugger())->slug($titre)->lower()->toString();
    }
}
