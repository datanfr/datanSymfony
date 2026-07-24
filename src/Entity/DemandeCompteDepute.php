<?php

namespace App\Entity;

use App\Repository\DemandeCompteDeputeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'ouverture d'un compte par un député (`/demande-compte-depute`).
 *
 * L'application d'origine ne stockait qu'un jeton éphémère (`users_mp_link`) :
 * le député saisissait son adresse institutionnelle, recevait par courriel un
 * lien d'activation valable 24 h, et créait lui-même son compte via
 * `/register/{token}`. La possession de l'adresse `@assemblee-nationale.fr`
 * tenait donc lieu de contrôle — seul le vrai député recevait le lien.
 *
 * Ce portage n'a pas d'envoi de courriel (Mailjet est une affaire de
 * déploiement, comme pour la newsletter), et `/register` relève des comptes
 * lecteurs, chantier séparé. La demande devient donc une ligne **en attente**
 * qu'un administrateur relit et approuve : c'est lui qui, au moment
 * d'approuver, transmet les identifiants à l'adresse institutionnelle — le
 * contrôle par l'adresse est reporté là, il n'est pas perdu.
 */
#[ORM\Entity(repositoryClass: DemandeCompteDeputeRepository::class)]
#[ORM\Table(name: 'demande_compte_depute')]
#[ORM\Index(name: 'idx_demande_depute', columns: ['depute_id'])]
#[ORM\Index(name: 'idx_demande_etat', columns: ['etat'])]
class DemandeCompteDepute
{
    public const EN_ATTENTE = 'en_attente';
    public const APPROUVEE = 'approuvee';
    public const REFUSEE = 'refusee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Depute $depute = null;

    /** L'adresse institutionnelle telle que saisie, conservée pour la relire. */
    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 20)]
    private string $etat = self::EN_ATTENTE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $demandeeLe;

    /** Renseignée quand un administrateur a approuvé ou refusé la demande. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $traiteeLe = null;

    public function __construct()
    {
        $this->demandeeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepute(): ?Depute
    {
        return $this->depute;
    }

    public function setDepute(Depute $depute): static
    {
        $this->depute = $depute;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function setEtat(string $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    public function estEnAttente(): bool
    {
        return $this->etat === self::EN_ATTENTE;
    }

    public function getDemandeeLe(): \DateTimeImmutable
    {
        return $this->demandeeLe;
    }

    public function getTraiteeLe(): ?\DateTimeImmutable
    {
        return $this->traiteeLe;
    }

    public function setTraiteeLe(?\DateTimeImmutable $traiteeLe): static
    {
        $this->traiteeLe = $traiteeLe;

        return $this;
    }
}
