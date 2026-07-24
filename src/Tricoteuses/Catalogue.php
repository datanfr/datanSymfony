<?php

namespace App\Tricoteuses;

/**
 * Les jeux de données des Tricoteuses dont dépend le site.
 *
 * Seule la législature en cours est moissonnée quotidiennement : les dépôts des
 * législatures closes ne bougent plus (le dernier commit de Scrutins_XVI date
 * de 2024) et sont importés une fois pour toutes.
 */
final class Catalogue
{
    private const BASE = 'https://git.en-root.org/tricoteuses/data/';

    /** @var array<string, Depot>|null */
    private static ?array $depots = null;

    /**
     * @return array<string, Depot>
     */
    public static function tous(): array
    {
        return self::$depots ??= [
            'acteurs' => new Depot(
                'acteurs',
                self::BASE . 'assemblee-nettoye/AMO30_tous_acteurs_tous_mandats_tous_organes_historique_nettoye.git',
                'Acteurs, mandats et organes de toutes les législatures',
            ),
            'dossiers' => new Depot(
                'dossiers',
                self::BASE . 'assemblee-nettoye/Dossiers_Legislatifs_XVII_nettoye.git',
                'Dossiers législatifs de la 17e législature',
            ),
            'scrutins' => new Depot(
                'scrutins',
                self::BASE . 'assemblee-nettoye/Scrutins_XVII_nettoye.git',
                'Scrutins de la 17e législature, ventilations et votes nominatifs',
            ),
            'amendements' => new Depot(
                'amendements',
                self::BASE . 'assemblee-nettoye/Amendements_XVII_nettoye.git',
                'Amendements de la 17e législature',
            ),
            'comptes-rendus' => new Depot(
                'comptes-rendus',
                self::BASE . 'assemblee-nettoye/Comptes_Rendus_Seances_XVII_nettoye.git',
                'Comptes rendus des séances publiques de la 17e législature',
            ),
            'photos' => new Depot(
                'photos',
                self::BASE . 'assemblee-photos/photos_deputes_17.git',
                'Photographies des députés de la 17e législature',
            ),
        ];
    }

    public static function get(string $nom): Depot
    {
        return self::tous()[$nom]
            ?? throw new \InvalidArgumentException(sprintf(
                'Dépôt « %s » inconnu. Disponibles : %s.',
                $nom,
                implode(', ', array_keys(self::tous())),
            ));
    }
}
