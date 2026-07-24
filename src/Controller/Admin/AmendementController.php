<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Repository\AmendementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de relecture des résumés d'amendements (`Admin::amendements`).
 *
 * La rédaction y relit, pour chaque vote portant sur un amendement, le titre et
 * le résumé rédigés automatiquement, puis coche « relu » quand ils sont bons.
 * Un vote relu peut alors être décrypté — d'où le bouton « Décrypter », qui
 * ouvre l'écran des décryptages pré-rempli sur le scrutin.
 *
 * Deux écarts de schéma assumés avec l'application d'origine, où ce socle IA
 * vivait dans une table `amendements_ia` **clée sur le vote** (`legislature`,
 * `voteNumero`) :
 *
 * - Chez nous, le résumé et son drapeau de relecture vivent sur l'amendement
 *   lui-même ({@see \App\Entity\Amendement} : `resume_ia`, `titre_ia`,
 *   `resume_relu`), qu'un scrutin désigne par sa clé `amendement_id`. Le
 *   « relu » du legacy (`amendements_ia.reviewed`, basculé par un endpoint AJAX)
 *   devient donc `amendement.resume_relu`.
 * - La note de simplicité (`amendements_ia.simplicite_ia`, rendue en étoiles)
 *   n'a pas encore de colonne dans notre schéma : la colonne est affichée vide
 *   (« — ») plutôt qu'inventée. Voir TODO.md §4.
 *
 * Accès : rédacteur **et** administrateur, comme le `security_only_team()` qui
 * garde tout le contrôleur `Admin` du legacy — aucune restriction plus fine sur
 * cet écran (à la différence des suppressions ailleurs dans le back-office).
 */
#[Route('/admin/amendements')]
#[IsGranted(Utilisateur::ROLE_REDACTEUR)]
class AmendementController extends AbstractController
{
    /** Périodes proposées, en jours ; « all » lève tout filtre de date (legacy). */
    private const PERIODES = ['all', '7', '30', '90', '180', '365'];
    private const PERIODE_DEFAUT = '30';

    /**
     * Natures de vote retenues, pendant du `voteType IN ('amendement','les amen')`
     * du legacy. Notre classement ({@see \App\NatureVote}) distingue le
     * sous-amendement de l'amendement : les deux sont des relectures d'amendement,
     * on prend l'un et l'autre.
     */
    private const NATURES = ['amendement', 'sous-amendement'];

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly AmendementRepository $amendements,
    ) {
    }

    #[Route('', name: 'admin_amendement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $legislatures = $this->legislaturesDisponibles();
        $legislature = (int) $request->query->get('legislature', (string) ($legislatures[0] ?? 17));
        if (!\in_array($legislature, $legislatures, true) && $legislatures !== []) {
            $legislature = $legislatures[0];
        }

        // « all » n'est pas une valeur libre : on la ramène au défaut si trafiquée.
        $periode = (string) $request->query->get('period', self::PERIODE_DEFAUT);
        if (!\in_array($periode, self::PERIODES, true)) {
            $periode = self::PERIODE_DEFAUT;
        }

        // Dates au format ISO seulement ; toute autre saisie est ignorée, comme
        // le legacy (une date malformée ne doit pas vider la liste par une erreur SQL).
        $dateDebut = $this->dateValide((string) $request->query->get('date_start', ''));
        $dateFin = $this->dateValide((string) $request->query->get('date_end', ''));

        $masquerRelus = $request->query->getBoolean('hide_reviewed');

        $sql = "SELECT
                    s.id AS scrutin_id,
                    s.legislature,
                    s.numero,
                    s.date_scrutin,
                    COALESCE(NULLIF(s.titre, ''), NULLIF(s.objet, ''), s.uid) AS titre,
                    s.nombre_votants AS votants,
                    a.id AS amendement_id,
                    a.titre_ia,
                    a.resume_ia,
                    COALESCE(a.resume_relu, 0) AS relu,
                    -- Écart de position adopté/rejeté, en points (daily.php) ; NULLIF
                    -- garde d'une division par zéro sur un scrutin sans votant.
                    ROUND(ABS(s.nombre_pour - s.nombre_contre) * 100 / NULLIF(s.nombre_votants, 0), 1) AS disparite,
                    -- « Intérêt » du scrutin : d'autant plus fort qu'il a mobilisé et
                    -- qu'il fut serré (même formule que le legacy).
                    ROUND(LEAST(s.nombre_votants / 250, 1) * (1 - ABS(s.nombre_pour - s.nombre_contre) / NULLIF(s.nombre_votants, 0)) * 100, 1) AS interet
                FROM scrutin s
                LEFT JOIN amendement a ON a.id = s.amendement_id
                -- Un vote déjà décrypté sort de la file de relecture (vd.id IS NULL du legacy).
                LEFT JOIN decryptage d ON d.scrutin_id = s.id
                WHERE s.nature_vote IN (:natures)
                  AND s.legislature = :legislature
                  AND d.id IS NULL";

        $params = [
            'natures' => self::NATURES,
            'legislature' => $legislature,
        ];
        $types = ['natures' => \Doctrine\DBAL\ArrayParameterType::STRING];

        // Dates explicites prioritaires sur la période, comme dans le legacy.
        if ($dateDebut !== null || $dateFin !== null) {
            if ($dateDebut !== null) {
                $sql .= ' AND s.date_scrutin >= :date_debut';
                $params['date_debut'] = $dateDebut . ' 00:00:00';
            }
            if ($dateFin !== null) {
                $sql .= ' AND s.date_scrutin <= :date_fin';
                $params['date_fin'] = $dateFin . ' 23:59:59';
            }
        } elseif ($periode !== 'all') {
            $sql .= ' AND s.date_scrutin >= DATE_SUB(CURDATE(), INTERVAL :jours DAY)';
            $params['jours'] = (int) $periode;
        }

        if ($masquerRelus) {
            $sql .= ' AND COALESCE(a.resume_relu, 0) = 0';
        }

        $sql .= ' ORDER BY s.date_scrutin DESC, s.numero DESC';

        $lignes = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $this->render('admin/amendement/index.html.twig', [
            'lignes' => $lignes,
            'legislatures' => $legislatures,
            'legislature' => $legislature,
            'periodes' => self::PERIODES,
            'periode' => $periode,
            'date_debut' => $dateDebut ?? '',
            'date_fin' => $dateFin ?? '',
            'masquer_relus' => $masquerRelus,
        ]);
    }

    /**
     * Bascule le drapeau « relu » d'un amendement (endpoint AJAX du legacy
     * `admin/amendements/review`, ici en POST + jeton CSRF).
     *
     * L'écriture passe par l'ORM : le drapeau `resume_relu` est une colonne
     * booléenne (SMALLINT 0/1), qu'il ne faut jamais lier en DBAL depuis un
     * booléen PHP — le pilote mysqli en ferait une chaîne vide refusée par la
     * colonne. Doctrine, lui, convertit proprement.
     */
    #[Route('/relu', name: 'admin_amendement_relu', methods: ['POST'])]
    public function relu(Request $request): JsonResponse
    {
        $corps = json_decode($request->getContent(), true) ?: [];

        if (!$this->isCsrfTokenValid('amendement_relu', (string) ($corps['_token'] ?? ''))) {
            return new JsonResponse(['erreur' => 'Jeton de sécurité invalide, rechargez la page.'], Response::HTTP_FORBIDDEN);
        }

        $id = (int) ($corps['amendement_id'] ?? 0);
        $relu = (bool) ($corps['reviewed'] ?? false);

        $amendement = $id > 0 ? $this->amendements->find($id) : null;
        if ($amendement === null) {
            return new JsonResponse(['erreur' => 'Amendement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $amendement->setResumeRelu($relu);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'reviewed' => $relu ? 1 : 0]);
    }

    /** Législatures qui portent des scrutins d'amendement, la plus récente en tête. */
    private function legislaturesDisponibles(): array
    {
        $valeurs = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT legislature FROM scrutin WHERE nature_vote IN (:natures) AND legislature IS NOT NULL ORDER BY legislature DESC',
            ['natures' => self::NATURES],
            ['natures' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map('intval', $valeurs);
    }

    /** Une date n'est retenue que sous la forme AAAA-MM-JJ, sinon écartée (legacy). */
    private function dateValide(string $valeur): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1 ? $valeur : null;
    }
}
