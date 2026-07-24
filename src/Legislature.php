<?php

namespace App;

/**
 * Repères de législature, portés des helpers de l'application CodeIgniter
 * d'origine (legislature_current(), legislature_all(), dissolution()).
 */
final class Legislature
{
    /** Législature en cours — `legislature_current()` dans l'application d'origine. */
    public const COURANTE = 17;

    /** Législature la plus ancienne publiée : en deçà, l'application d'origine renvoie un 404. */
    public const PREMIERE = 14;

    /**
     * Législatures proposées dans les blocs « Historique », de la plus récente
     * à la plus ancienne.
     *
     * @return list<int>
     */
    public static function publiees(): array
    {
        return range(self::COURANTE, self::PREMIERE);
    }
}
