<?php

namespace App\Twig;

use App\Command\ImportPhotosCommand;
use App\Entity\Depute;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adresse de la photographie d'un député, avec repli sur le visage générique du
 * site quand elle manque.
 *
 * Les photos sont publiées par {@see ImportPhotosCommand} sous
 * `public/assets/imgs/deputes/`, nommées par la partie numérique du `mp_id`.
 * L'existence du fichier fait foi : rien en base ne dit qui a une photo, et
 * c'est voulu — une colonne le prétendrait sans jamais être vérifiée.
 *
 * Le dossier n'est lu qu'une fois par requête, à la première photo demandée.
 * Une page de liste appelle la fonction 577 fois : autant d'appels à
 * `is_file()` coûteraient 577 accès disque là où un seul suffit.
 */
class PhotoExtension extends AbstractExtension
{
    /** Le visage générique qu'affichaient jusqu'ici toutes les fiches. */
    private const PLACEHOLDER = 'assets/imgs/placeholder/placeholder-face.png';

    /** @var array<string, true>|null identifiants dont la photo est publiée */
    private ?array $publiees = null;

    public function __construct(
        private readonly Packages $assets,
        #[Autowire('%kernel.project_dir%')] private readonly string $racineProjet,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('photo_depute', $this->photoDepute(...)),
        ];
    }

    /**
     * @param array<string, mixed>|Depute|string|null $depute une ligne de
     *   résultat portant `mp_id`, une entité, ou l'identifiant d'acteur lui-même
     */
    public function photoDepute(array|Depute|string|null $depute): string
    {
        $identifiant = $this->identifiant($depute);

        if ($identifiant === null || !isset($this->index()[$identifiant])) {
            return $this->assets->getUrl(self::PLACEHOLDER);
        }

        return $this->assets->getUrl(ImportPhotosCommand::DOSSIER_PUBLIC . '/' . $identifiant . '.jpg');
    }

    /**
     * Dit si le portrait est publié, sans donner d'adresse : le générateur de
     * cartes Open Graph ({@see \App\Referencement\OpenGraph}) reçoit ce fait en
     * `img=0/1` et compose sa carte sans photo plutôt qu'avec une image cassée.
     *
     * @param array<string, mixed>|Depute|string|null $depute
     */
    public function aUnePhoto(array|Depute|string|null $depute): bool
    {
        $identifiant = $this->identifiant($depute);

        return $identifiant !== null && isset($this->index()[$identifiant]);
    }

    /**
     * Partie numérique de l'identifiant d'acteur : `PA720892` donne `720892`.
     *
     * @param array<string, mixed>|Depute|string|null $depute
     */
    private function identifiant(array|Depute|string|null $depute): ?string
    {
        $mpId = match (true) {
            $depute instanceof Depute => $depute->getMpId(),
            \is_array($depute) => $depute['mp_id'] ?? $depute['mpId'] ?? null,
            default => $depute,
        };

        if (!\is_string($mpId)) {
            return null;
        }

        $numerique = str_starts_with($mpId, 'PA') ? substr($mpId, 2) : $mpId;

        return ctype_digit($numerique) ? $numerique : null;
    }

    /**
     * @return array<string, true>
     */
    private function index(): array
    {
        if ($this->publiees !== null) {
            return $this->publiees;
        }

        $dossier = $this->racineProjet . '/public/' . ImportPhotosCommand::DOSSIER_PUBLIC;
        $this->publiees = [];

        // Tant que l'import n'a pas tourné, le dossier n'existe pas : toutes les
        // fiches gardent le placeholder, sans erreur.
        if (!is_dir($dossier)) {
            return $this->publiees;
        }

        foreach (scandir($dossier) ?: [] as $fichier) {
            if (str_ends_with($fichier, '.jpg')) {
                $this->publiees[basename($fichier, '.jpg')] = true;
            }
        }

        return $this->publiees;
    }
}
