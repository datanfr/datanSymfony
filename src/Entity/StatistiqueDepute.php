<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Statistiques précalculées d'un député pour une législature : participation aux
 * scrutins solennels et proximité (loyauté) avec son groupe. Alimente les cartes
 * « Son comportement politique » de la fiche (`class_participation_solennels` et
 * `class_loyaute` de l'application d'origine).
 *
 * **Précalcul, comme le legacy** (`daily.php`) : ces cartes comparent le député à
 * la moyenne de tous les députés et à celle de son groupe. Recalculer ces
 * moyennes à chaque affichage supposerait de rejouer 1,27 M de votes par requête
 * — impossible en ~20 ms. La commande `app:calcul:statistiques-deputes` remplit
 * cette table ; la fiche s'y lit en DBAL.
 *
 * Les votes nominatifs n'existant que pour la 17e législature (cf. CLAUDE.md),
 * cette table ne porte qu'elle : une fiche d'une législature antérieure n'a pas
 * de statistiques et se tait, au lieu d'afficher zéro.
 */
#[ORM\Entity]
#[ORM\Table(name: 'statistique_depute')]
#[ORM\UniqueConstraint(name: 'uniq_statistique_depute', columns: ['depute_id', 'legislature'])]
class StatistiqueDepute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $deputeId = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $legislature = null;

    /** Taux de participation aux scrutins solennels, en %, ou null si aucun encore tenu. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $participationScore = null;

    #[ORM\Column]
    private int $participationVotes = 0;

    /** Taux de proximité avec le groupe, en %, ou null si trop peu de votes. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $loyauteScore = null;

    #[ORM\Column]
    private int $loyauteVotes = 0;

    /** 1 si le député siège encore (mandat sans date de fin), 0 sinon. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $actif = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeputeId(): ?int
    {
        return $this->deputeId;
    }

    public function setDeputeId(int $deputeId): static
    {
        $this->deputeId = $deputeId;

        return $this;
    }

    public function getLegislature(): ?int
    {
        return $this->legislature;
    }

    public function setLegislature(int $legislature): static
    {
        $this->legislature = $legislature;

        return $this;
    }

    public function getParticipationScore(): ?int
    {
        return $this->participationScore;
    }

    public function setParticipationScore(?int $participationScore): static
    {
        $this->participationScore = $participationScore;

        return $this;
    }

    public function getParticipationVotes(): int
    {
        return $this->participationVotes;
    }

    public function setParticipationVotes(int $participationVotes): static
    {
        $this->participationVotes = $participationVotes;

        return $this;
    }

    public function getLoyauteScore(): ?int
    {
        return $this->loyauteScore;
    }

    public function setLoyauteScore(?int $loyauteScore): static
    {
        $this->loyauteScore = $loyauteScore;

        return $this;
    }

    public function getLoyauteVotes(): int
    {
        return $this->loyauteVotes;
    }

    public function setLoyauteVotes(int $loyauteVotes): static
    {
        $this->loyauteVotes = $loyauteVotes;

        return $this;
    }

    public function getActif(): int
    {
        return $this->actif;
    }

    public function setActif(int $actif): static
    {
        $this->actif = $actif;

        return $this;
    }
}
