<?php

namespace App\Enum;

/**
 * Les classements précalculés par `app:calcul:classements`, portés des tables
 * `class_participation_solennels`, `class_loyaute`, `class_groups` et
 * `groupes_stats` de l'application d'origine.
 *
 * Chaque cas désigne un couple (population classée, critère). Une page de
 * classement peut en afficher plusieurs : la participation est présentée en
 * deux onglets, aux scrutins solennels et à tous les scrutins.
 */
enum TypeClassement: string
{
    /** Députés, participation aux seuls scrutins solennels — le score mis en avant par le site. */
    case DeputesParticipation = 'deputes_participation';

    /** Députés, participation à tous les scrutins de la législature. */
    case DeputesParticipationTous = 'deputes_participation_tous';

    /** Députés, part des votes conformes à la position majoritaire de leur groupe. */
    case DeputesLoyaute = 'deputes_loyaute';

    case DeputesAge = 'deputes_age';

    /** Groupes, indice d'accord moyen sur leurs ventilations de scrutin. */
    case GroupesCohesion = 'groupes_cohesion';

    case GroupesParticipation = 'groupes_participation';

    case GroupesParticipationTous = 'groupes_participation_tous';

    case GroupesAge = 'groupes_age';

    case GroupesFeminisation = 'groupes_feminisation';

    /** Groupes, indice de Rose : proximité de leur composition sociale avec celle du pays. */
    case GroupesOrigineSociale = 'groupes_origine_sociale';

    /** Un classement de groupes porte sur `groupe_id`, les autres sur `depute_id`. */
    public function porteSurUnGroupe(): bool
    {
        return str_starts_with($this->value, 'groupes_');
    }
}
