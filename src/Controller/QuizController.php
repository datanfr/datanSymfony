<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Quel député choisir ? », portée du contrôleur Quiz de l'application
 * d'origine (application/controllers/Quiz.php, vue quiz/index.php).
 *
 * Attention au périmètre : la page ne consomme PAS la table `question_quiz`
 * (l'ancien `quizz`, récupérée par `app:import:quiz`). Celle-ci n'alimente que
 * `Quizz_model::get_questions_api()`, une méthode branchée sur aucune route de
 * `routes.php` — un service pour une application tierce, sans page. Ce que
 * `/questionnaire` affiche, c'est `Votes_model::get_most_famous_votes(3)` : les
 * trois derniers votes décryptés, proposés en « pour / contre / abstention »
 * avec un poids. Le résultat n'a jamais été terminé côté legacy (voir
 * {@see self::resultat()}).
 */
class QuizController extends AbstractController
{
    /**
     * Le contenu suit le rythme des décryptages publiés — quelques-uns par
     * semaine. Une heure de cache partagé, comme les autres pages de vote.
     */
    private const CACHE_TTL = 3600;

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/questionnaire', name: 'quiz_index', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('quiz/index.html.twig', [
            'votes' => $this->derniersDecryptes(),
            'fil_ariane' => [
                ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
                ['nom' => 'Quel député choisir ?', 'url' => $this->generateUrl('quiz_index')],
            ],
        ]);

        $response->setPublic();
        $response->setSharedMaxAge(self::CACHE_TTL);

        return $response;
    }

    /**
     * Cible du bouton « Calculer ».
     *
     * Le calcul de proximité était resté à l'état d'ébauche dans l'application
     * d'origine : `Quiz::result()` recharge la vue `quiz/index` sans lui passer
     * de votes (tout l'affichage du score y est commenté), si bien que datan.fr
     * ne rend qu'un questionnaire vide. Plutôt que de reproduire cette page
     * morte — ou d'inventer un résultat que le legacy n'a jamais montré —, on
     * redonne le questionnaire renseigné. L'adresse reste servie (le formulaire
     * n'ouvre plus sur un 405), sans prétendre à un score qui n'existe pas.
     */
    #[Route('/questionnaire/resultat', name: 'quiz_resultat', methods: ['POST'])]
    public function resultat(): Response
    {
        return $this->render('quiz/index.html.twig', [
            'votes' => $this->derniersDecryptes(),
            'fil_ariane' => [
                ['nom' => 'Datan', 'url' => $this->generateUrl('home')],
                ['nom' => 'Quel député choisir ?', 'url' => $this->generateUrl('quiz_index')],
            ],
        ]);
    }

    /**
     * Les trois derniers votes décryptés, comme `get_most_famous_votes(3)`.
     *
     * L'application d'origine ordonne par `votes_info.voteNumero DESC` ; notre
     * équivalent est `scrutin.numero`, et le tri rend les trois mêmes scrutins
     * que la base de production interrogée à neuf (le PLFSS 2026, décembre 2025).
     * Le titre affiché est celui de la rédaction (`decryptage.title`), pas
     * l'intitulé brut du scrutin. Aucun lien n'est construit ici : les titres
     * sont du texte, la page ne renvoie pas vers les fiches de vote.
     *
     * @return list<array{titre: string, numero: int}>
     */
    private function derniersDecryptes(): array
    {
        $lignes = $this->connection->fetchAllAssociative(
            "SELECT dcr.title AS titre, s.numero
             FROM decryptage dcr
             JOIN scrutin s ON s.id = dcr.scrutin_id
             WHERE dcr.state = 'published'
             ORDER BY s.numero DESC
             LIMIT 3",
        );

        return array_map(
            static fn (array $l): array => ['titre' => (string) $l['titre'], 'numero' => (int) $l['numero']],
            $lignes,
        );
    }
}
