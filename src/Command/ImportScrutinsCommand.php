<?php

namespace App\Command;

use App\CorrectionTitreScrutin;
use App\NatureVote;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les scrutins depuis le dépôt des Tricoteuses.
 *
 * Un fichier de scrutin porte à lui seul tout ce que le site affiche d'un
 * vote : la synthèse, le dossier législatif auquel il se rattache, la
 * ventilation par groupe et le détail nominatif des votes. Les trois tables
 * sont donc remplies en une passe, ce qui garantit qu'elles restent cohérentes
 * entre elles.
 */
#[AsCommand(
    name: 'app:import:scrutins',
    description: 'Importe scrutins, ventilations par groupe et votes nominatifs depuis le dépôt des Tricoteuses.',
)]
class ImportScrutinsCommand extends ImportTricoteusesCommand
{
    /** Vote officiel, par opposition aux rectifications déposées après coup. */
    private const VOTE_OFFICIEL = 'decompteNominatif';

    /** Rectification d'un député affichée par le site à côté du vote officiel. */
    private const MISE_AU_POINT = 'miseAuPoint';

    /** Position majoritaire d'un groupe dont aucun membre ne s'est exprimé. */
    private const NON_VOTANT = 'nv';

    /**
     * Positions du décompte nominatif, et le nom qu'elles portent dans la base.
     * L'open data emploie le pluriel pour les listes, le site le singulier.
     */
    private const POSITIONS = [
        'pour' => 'pour',
        'contre' => 'contre',
        'abstentions' => 'abstention',
        'nonVotants' => 'nonVotant',
    ];

    private const COLONNES_SCRUTIN = [
        'uid', 'numero', 'legislature', 'date_scrutin', 'titre', 'objet',
        'sort_code', 'sort_libelle', 'type_vote', 'code_type_vote', 'nature_vote',
        'demandeur', 'seance_ref', 'nombre_votants', 'suffrages_exprimes', 'nombre_pour',
        'nombre_contre', 'nombre_abstentions', 'nombre_non_votants', 'dossier_id',
        'created_at', 'updated_at',
    ];

    /**
     * Colonnes réécrites quand le scrutin existe déjà.
     *
     * `dossier_id` en est délibérément absent : l'Assemblée ne publie le
     * dossier dans le scrutin que dans un cas sur trois, et un import qui
     * réécrirait cette colonne effacerait les rattachements établis par
     * ailleurs. Le rattachement se fait donc à part, et jamais en retirant.
     */
    private const COLONNES_MAJ_SCRUTIN = [
        'numero', 'legislature', 'date_scrutin', 'titre', 'objet',
        'sort_code', 'sort_libelle', 'type_vote', 'code_type_vote', 'nature_vote',
        'demandeur', 'seance_ref', 'nombre_votants', 'suffrages_exprimes', 'nombre_pour',
        'nombre_contre', 'nombre_abstentions', 'nombre_non_votants', 'updated_at',
    ];

    private const COLONNES_VENTILATION = [
        'scrutin_id', 'groupe_id', 'nombre_membres_groupe', 'position_majoritaire',
        'nombre_pours', 'nombre_contres', 'nombre_abstentions', 'non_votants',
        'non_votants_volontaires',
    ];

    private const COLONNES_VOTE = [
        'depute_id', 'scrutin_id', 'position', 'vote_type', 'cause_position',
        'par_delegation', 'scrutin_date', 'created_at', 'updated_at',
    ];

    /** Votes nominatifs écartés faute de député connu (acteurRef absent de `depute`). */
    private int $votesEcartes = 0;

    protected function depotParDefaut(): string
    {
        return 'scrutins';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('sans-votes', null, InputOption::VALUE_NONE, 'N\'importe pas le détail nominatif des votes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tout = (bool) $input->getOption('tout');
        $sansVotes = (bool) $input->getOption('sans-votes');
        $chemin = $this->chemin($input);

        $io->title('Import des scrutins');
        $io->text($this->incremental($chemin, $tout) ? 'Régime incrémental : seuls les scrutins modifiés.' : 'Import complet du dépôt.');

        $maintenant = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $dossiers = $this->connection->fetchAllKeyValue('SELECT dossier_id, id FROM dossier');
        $groupes = $this->connection->fetchAllKeyValue('SELECT uid, id FROM groupe');
        $deputes = $this->connection->fetchAllKeyValue('SELECT mp_id, id FROM depute');

        // Premier passage : les scrutins, dont on a besoin des identifiants
        // pour rattacher ventilations et votes.
        $uids = [];
        $refsDossier = [];
        $lot = [];
        // Les dépôts n'ont pas tous la même charpente : Scrutins XVI et XVII
        // séparent l'Assemblée (`AN/`) du Congrès (`CG/`), que l'on écarte —
        // ses votes vivent sous `vote_c<n>` et son numéro entre en collision
        // avec celui d'un scrutin ordinaire. Scrutins XIV et XV rangent tout à
        // la racine (`R5/`), sans aucun fichier du Congrès : on y lit tout.
        $sousDossier = is_dir($chemin . '/AN') ? 'AN' : '';
        foreach ($this->fichiers($chemin, $sousDossier, $tout) as $fichier) {
            $scrutin = $this->lisJson($fichier);
            if ($scrutin === null || !isset($scrutin['uid'])) {
                continue;
            }

            $uids[$scrutin['uid']] = $fichier;

            $dossierId = $dossiers[$scrutin['objet']['dossierLegislatif']['dossierRef'] ?? ''] ?? null;
            if ($dossierId !== null) {
                $refsDossier[$scrutin['uid']] = (int) $dossierId;
            }

            $lot[] = $this->ligneScrutin($scrutin, $dossiers, $maintenant);

            if (\count($lot) >= self::TAILLE_LOT) {
                $this->upsert('scrutin', self::COLONNES_SCRUTIN, $lot, self::COLONNES_MAJ_SCRUTIN);
                $lot = [];
            }
        }
        $this->upsert('scrutin', self::COLONNES_SCRUTIN, $lot, self::COLONNES_MAJ_SCRUTIN);

        if ($uids === []) {
            $io->success('Aucun scrutin à importer.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d scrutin%s lu%s.', \count($uids), \count($uids) > 1 ? 's' : '', \count($uids) > 1 ? 's' : ''));

        $identifiants = $this->identifiantsScrutins(array_keys($uids));

        if ($refsDossier !== []) {
            $io->text(sprintf('Dossiers rattachés depuis les scrutins : %d.', $this->rattacheDossiers($refsDossier, $identifiants)));
        }

        $ventilations = 0;
        $ventilationsEcartees = 0;
        $votes = 0;
        $this->votesEcartes = 0;
        $lotVentilation = [];
        $lotVote = [];

        foreach ($uids as $uid => $fichier) {
            $scrutin = $this->lisJson($fichier);
            if ($scrutin === null) {
                continue;
            }

            $scrutinId = $identifiants[$uid] ?? null;
            if ($scrutinId === null) {
                continue;
            }

            $date = $this->date($scrutin['dateScrutin'] ?? null);

            foreach ($scrutin['ventilationVotes']['groupes'] ?? [] as $groupe) {
                // L'Assemblée publie les ventilations du groupe socialiste de
                // la 16e sous PO800496 même après son changement de nom en
                // SOC-A (PO830170) le 19/10/2023 : sans réattribution, 581
                // ventilations restent au groupe dissous, qui « vote » alors
                // cinq mois après sa disparition — participation, cohésion et
                // proximités des deux SOC en sortent faussées. Même correctif
                // que le legacy (daily.php:1430, « Bug fix for socialist
                // group »).
                $organeRef = $groupe['organeRef'] ?? '';
                if ($organeRef === 'PO800496' && $date !== null && $date >= '2023-10-19') {
                    $organeRef = 'PO830170';
                }

                $groupeId = $groupes[$organeRef] ?? null;
                if ($groupeId === null) {
                    ++$ventilationsEcartees;
                    continue;
                }

                $lotVentilation[] = $this->ligneVentilation($groupe, $scrutinId, $groupeId);
                ++$ventilations;

                if (!$sansVotes) {
                    foreach ($this->votesNominatifs($groupe['vote']['decompteNominatif'] ?? [], $deputes, $scrutinId, $date, self::VOTE_OFFICIEL, $maintenant) as $ligne) {
                        $lotVote[] = $ligne;
                        ++$votes;
                    }
                }

                if (\count($lotVentilation) >= self::TAILLE_LOT) {
                    $this->upsert('vote_groupe', self::COLONNES_VENTILATION, $lotVentilation, \array_slice(self::COLONNES_VENTILATION, 2));
                    $lotVentilation = [];
                }
            }

            // Les mises au point sont des rectifications déposées après le
            // scrutin ; le site les affiche à côté du vote officiel, qu'elles
            // ne remplacent pas — d'où leur propre vote_type.
            if (!$sansVotes) {
                foreach ($this->votesNominatifs($scrutin['miseAuPoint'] ?? [], $deputes, $scrutinId, $date, self::MISE_AU_POINT, $maintenant) as $ligne) {
                    $lotVote[] = $ligne;
                    ++$votes;
                }
            }

            if (\count($lotVote) >= 2000) {
                $this->upsert('vote', self::COLONNES_VOTE, $lotVote, ['position', 'cause_position', 'par_delegation', 'scrutin_date', 'updated_at']);
                $lotVote = [];
            }
        }

        $this->upsert('vote_groupe', self::COLONNES_VENTILATION, $lotVentilation, \array_slice(self::COLONNES_VENTILATION, 2));
        $this->upsert('vote', self::COLONNES_VOTE, $lotVote, ['position', 'cause_position', 'par_delegation', 'scrutin_date', 'updated_at']);

        $io->text(sprintf('%d ventilations par groupe, %d votes nominatifs.', $ventilations, $votes));

        // Un import qui écarte des lignes rend l'écart à l'unité : sans ce
        // bilan, une règle fausse ressemble en tout point à une source
        // incomplète (cf. CLAUDE.md).
        if ($ventilationsEcartees > 0 || $this->votesEcartes > 0) {
            $io->warning(sprintf(
                'Écartés : %d ventilation%s (groupe inconnu de la table `groupe`), %d vote%s (député inconnu de la table `depute`).',
                $ventilationsEcartees,
                $ventilationsEcartees > 1 ? 's' : '',
                $this->votesEcartes,
                $this->votesEcartes > 1 ? 's' : '',
            ));
        }

        $rattaches = $this->rattacheDecryptages();
        $io->text(sprintf('Décryptages rattachés à leur scrutin : %d.', $rattaches));

        $io->success(sprintf('%d scrutins en base.', (int) $this->connection->fetchOne('SELECT COUNT(*) FROM scrutin')));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int|string> $dossiers
     *
     * @return list<mixed>
     */
    private function ligneScrutin(array $scrutin, array $dossiers, string $maintenant): array
    {
        $objet = $scrutin['objet']['libelle'] ?? null;
        $dossierRef = $scrutin['objet']['dossierLegislatif']['dossierRef'] ?? null;
        $decompte = $scrutin['syntheseVote']['decompte'] ?? [];

        return [
            $scrutin['uid'],
            $this->entier($scrutin['numero'] ?? null),
            $this->entier($scrutin['legislature'] ?? null),
            $this->date($scrutin['dateScrutin'] ?? null),
            // Coquilles et intitulés périmés de l'open data, corrigés à la
            // main comme sur datan.fr. L'objet reste brut : il n'est affiché
            // nulle part et app:lien:scrutins y lit le numéro d'amendement.
            CorrectionTitreScrutin::corriger($scrutin['titre'] ?? null),
            $objet,
            $scrutin['sort']['code'] ?? null,
            $scrutin['sort']['libelle'] ?? null,
            $scrutin['typeVote']['libelleTypeVote'] ?? null,
            $scrutin['typeVote']['codeTypeVote'] ?? null,
            NatureVote::depuisLibelle($objet ?? $scrutin['titre'] ?? null),
            $scrutin['demandeur']['texte'] ?? null,
            // La séance relie le scrutin à son compte rendu, donc aux débats
            // qui l'ont précédé (brouillon de décryptage par IA).
            $scrutin['seanceRef'] ?? null,
            $this->entier($scrutin['syntheseVote']['nombreVotants'] ?? null),
            $this->entier($scrutin['syntheseVote']['suffragesExprimes'] ?? null),
            $this->entier($decompte['pour'] ?? null),
            $this->entier($decompte['contre'] ?? null),
            $this->entier($decompte['abstentions'] ?? null),
            $this->entier($decompte['nonVotants'] ?? null),
            $dossierRef !== null ? ($dossiers[$dossierRef] ?? null) : null,
            $maintenant,
            $maintenant,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function ligneVentilation(array $groupe, int $scrutinId, int $groupeId): array
    {
        $voix = $groupe['vote']['decompteVoix'] ?? [];
        $pour = (int) ($voix['pour'] ?? 0);
        $contre = (int) ($voix['contre'] ?? 0);
        $abstentions = (int) ($voix['abstentions'] ?? 0);

        // Ne PAS reprendre le `positionMajoritaire` publié par l'Assemblée : il ne
        // départage que pour et contre, en ignorant les abstentions — un groupe à
        // 4 pour / 1 contre / 17 abstentions y est déclaré « pour ». Le site
        // recalcule depuis toujours la pluralité stricte sur les trois positions
        // (daily.php:1439-1449) : égalité ou personne d'exprimé → « nv ». S'écarter
        // de cette règle avait décalé loyautés et proximités de tout le site
        // (6 983 ventilations divergentes, Bernalicis à 98 % au lieu de 100 %).
        $position = match (true) {
            $pour + $contre + $abstentions === 0 => self::NON_VOTANT,
            $pour > $contre && $pour > $abstentions => 'pour',
            $contre > $pour && $contre > $abstentions => 'contre',
            $abstentions > $pour && $abstentions > $contre => 'abstention',
            default => self::NON_VOTANT,
        };

        return [
            $scrutinId,
            $groupeId,
            (int) ($groupe['nombreMembresGroupe'] ?? 0),
            $position,
            $pour,
            $contre,
            $abstentions,
            (int) ($voix['nonVotants'] ?? 0),
            (int) ($voix['nonVotantsVolontaires'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed>      $decompte
     * @param array<string, int|string> $deputes
     *
     * @return iterable<list<mixed>>
     */
    private function votesNominatifs(array $decompte, array $deputes, int $scrutinId, ?string $date, string $type, string $maintenant): iterable
    {
        foreach (self::POSITIONS as $cle => $position) {
            foreach ($decompte[$cle] ?? [] as $votant) {
                $deputeId = $deputes[$votant['acteurRef'] ?? ''] ?? null;
                if ($deputeId === null) {
                    ++$this->votesEcartes;
                    continue;
                }

                yield [
                    $deputeId,
                    $scrutinId,
                    $position,
                    $type,
                    $votant['causePositionVote'] ?? null,
                    !empty($votant['parDelegation']) ? 1 : 0,
                    $date,
                    $maintenant,
                    $maintenant,
                ];
            }
        }
    }

    /**
     * Rattache les scrutins à leur dossier législatif.
     *
     * Écrit par un UPDATE dédié plutôt que par l'upsert, pour ne jamais
     * effacer un rattachement que l'Assemblée aurait omis de republier.
     *
     * @param array<string, int> $refsDossier  uid du scrutin → id du dossier
     * @param array<string, int> $identifiants uid du scrutin → id du scrutin
     */
    private function rattacheDossiers(array $refsDossier, array $identifiants): int
    {
        $paires = [];
        foreach ($refsDossier as $uid => $dossierId) {
            if (isset($identifiants[$uid])) {
                $paires[$identifiants[$uid]] = $dossierId;
            }
        }

        $rattaches = 0;
        foreach (array_chunk($paires, 500, true) as $paquet) {
            $cas = '';
            $parametres = [];
            foreach ($paquet as $scrutinId => $dossierId) {
                $cas .= ' WHEN ? THEN ?';
                $parametres[] = $scrutinId;
                $parametres[] = $dossierId;
            }

            $rattaches += (int) $this->connection->executeStatement(
                sprintf(
                    'UPDATE scrutin SET dossier_id = CASE id%s END WHERE id IN (%s)',
                    $cas,
                    implode(', ', array_fill(0, \count($paquet), '?')),
                ),
                [...$parametres, ...array_keys($paquet)],
            );
        }

        return $rattaches;
    }

    /**
     * @param list<string> $uids
     *
     * @return array<string, int>
     */
    private function identifiantsScrutins(array $uids): array
    {
        $identifiants = [];

        foreach (array_chunk($uids, 1000) as $paquet) {
            $identifiants += $this->connection->fetchAllKeyValue(
                'SELECT uid, id FROM scrutin WHERE uid IN (?)',
                [$paquet],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        return array_map(intval(...), $identifiants);
    }

    /**
     * Rattache les décryptages éditoriaux aux scrutins. Idempotent.
     *
     * On privilégie l'identifiant de scrutin (vote_id ↔ uid) : c'est la clé la
     * plus sûre, et la seule qui fonctionne pour les votes du Congrès, que
     * l'application d'origine stockait avec un numéro sentinelle -1 parce que
     * leur numérotation entre en collision avec celle de l'Assemblée.
     */
    private function rattacheDecryptages(): int
    {
        $rattaches = (int) $this->connection->executeStatement(
            'UPDATE decryptage d
             JOIN scrutin s ON s.uid = d.vote_id
             SET d.scrutin_id = s.id
             WHERE d.vote_id IS NOT NULL AND d.vote_id <> \'\'
               AND (d.scrutin_id IS NULL OR d.scrutin_id <> s.id)'
        );

        return $rattaches + (int) $this->connection->executeStatement(
            'UPDATE decryptage d
             JOIN scrutin s ON s.legislature = d.legislature AND s.numero = d.vote_numero
             SET d.scrutin_id = s.id
             WHERE d.scrutin_id IS NULL'
        );
    }
}
