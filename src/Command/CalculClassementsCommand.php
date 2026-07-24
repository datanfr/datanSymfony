<?php

namespace App\Command;

use App\Enum\TypeClassement;
use App\FamilleSocioPro;
use App\Legislature;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remplit la table `classement` : les palmarès affichés sous /statistiques.
 *
 * Équivalent des méthodes `classParticipationSolennels()`, `classLoyaute()`,
 * `classGroups()` et `groupeStats()` de `scripts/daily.php` dans l'application
 * d'origine, qui tournaient chaque nuit. Rien n'est calculé à l'affichage :
 * classer les 577 députés à la participation suppose d'agréger le million de
 * lignes de `vote`, ce qu'aucune page ne peut se permettre.
 *
 * Les définitions métier ne sont pas réinventées : la participation et la
 * loyauté sont celles de {@see \App\Controller\DeputeController}, la cohésion
 * et la féminisation celles de {@see \App\Controller\GroupeController}.
 *
 * À lancer en `APP_ENV=prod` : en dev, le logger Doctrine journalise chaque
 * requête et fait exploser la mémoire sur les agrégats de `vote`.
 */
#[AsCommand(
    name: 'app:calcul:classements',
    description: 'Recalcule les classements des députés et des groupes (/statistiques).',
)]
class CalculClassementsCommand extends Command
{
    private const BATCH_SIZE = 500;

    /** Décompte officiel du scrutin, par opposition aux mises au point postérieures. */
    private const OFFICIEL = 'decompteNominatif';

    /** Scrutins publics solennels — ceux sur lesquels le site juge l'assiduité. */
    private const SOLENNEL = 'SPS';

    /**
     * Motions de censure : exclues des taux de participation « tous scrutins »
     * par l'application d'origine, seuls leurs signataires prenant part au vote.
     */
    private const MOTION_CENSURE = 'MOC';

    /** Positions comptées comme un vote exprimé. */
    private const EXPRIMES = "('pour', 'contre', 'abstention')";

    /**
     * Les non-inscrits ne forment pas un groupe : l'application d'origine les
     * écarte des classements qui décrivent une composition (âge, féminisation,
     * représentativité sociale), mais les garde dans ceux qui décrivent un
     * comportement de vote (cohésion, participation).
     */
    private const NON_INSCRITS = 'NI';

    /**
     * Prédicat « ce député siège encore », à substituer de l'alias de `depute`
     * et à accompagner d'un paramètre `:legislature`.
     *
     * Le seul rattachement à un groupe de la législature ne suffit pas : la
     * table `depute` garde les groupes des députés partis en cours de mandat,
     * et les compter gonflerait tous les effectifs. `depute.date_fin` ne
     * tranche pas davantage, n'étant pas rafraîchie aux réélections. Le statut
     * se lit sur les mandats, comme sur la page d'un député : un mandat sans
     * date de fin, c'est un siège encore occupé.
     */
    private const EN_EXERCICE = 'EXISTS (SELECT 1 FROM mandat m
                                         WHERE m.depute_id = %s.id
                                           AND m.legislature = :legislature
                                           AND m.date_fin IS NULL)';

    /** Indice d'accord d'une ligne de ventilation, identique à celui des pages de groupe. */
    private const COHESION_SQL = '(GREATEST(vg.nombre_pours, vg.nombre_contres, vg.nombre_abstentions)
            - 0.5 * ((vg.nombre_pours + vg.nombre_contres + vg.nombre_abstentions)
                     - GREATEST(vg.nombre_pours, vg.nombre_contres, vg.nombre_abstentions)))
           / NULLIF(vg.nombre_pours + vg.nombre_contres + vg.nombre_abstentions, 0)';

    private \DateTimeImmutable $aujourdhui;

    /** @var array<int, string>|null Sigles des groupes, pour départager les ex æquo. */
    private ?array $sigles = null;

    /** @var array<int, int>|null Effectif courant de chaque groupe. */
    private ?array $effectifs = null;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
        $this->aujourdhui = new \DateTimeImmutable('today');
    }

    protected function configure(): void
    {
        $this->addOption(
            'legislature',
            null,
            InputOption::VALUE_REQUIRED,
            'Législature à classer',
            (string) Legislature::COURANTE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $legislature = (int) $input->getOption('legislature');

        $io->title(sprintf('Calcul des classements — %de législature', $legislature));

        $depart = microtime(true);
        $lignes = [];

        foreach ($this->participationDeputes($legislature) as $type => $classement) {
            $lignes[$type] = $classement;
        }

        $lignes[TypeClassement::DeputesLoyaute->value] = $this->loyauteDeputes($legislature);
        $lignes[TypeClassement::DeputesAge->value] = $this->ageDeputes($legislature);
        $lignes[TypeClassement::GroupesCohesion->value] = $this->cohesionGroupes($legislature);
        $lignes[TypeClassement::GroupesParticipation->value] = $this->participationGroupes($legislature, true);
        $lignes[TypeClassement::GroupesParticipationTous->value] = $this->participationGroupes($legislature, false);
        $lignes[TypeClassement::GroupesAge->value] = $this->ageGroupes($legislature);
        $lignes[TypeClassement::GroupesFeminisation->value] = $this->feminisationGroupes($legislature);
        $lignes[TypeClassement::GroupesOrigineSociale->value] = $this->origineSocialeGroupes($legislature);

        $this->connection->beginTransaction();

        try {
            foreach ($lignes as $type => $classement) {
                $this->remplace(TypeClassement::from($type), $legislature, $classement);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error(sprintf('Calcul interrompu, aucun classement modifié : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->table(
            ['Classement', 'Lignes'],
            array_map(static fn (string $t, array $c) => [$t, \count($c)], array_keys($lignes), $lignes),
        );

        $io->success(sprintf(
            '%d lignes de classement écrites en %.1f s.',
            array_sum(array_map('count', $lignes)),
            microtime(true) - $depart,
        ));

        return Command::SUCCESS;
    }

    /**
     * Taux de participation des députés, aux scrutins solennels puis à tous les
     * scrutins de la législature.
     *
     * Le dénominateur compte TOUS les scrutins tenus pendant la période
     * d'activité du député, et non les seuls scrutins où il a une ligne de
     * vote : une absence complète ne laisse aucune trace dans `vote`, et
     * l'ignorer donnerait 100 % à un député pourtant absent. Faute d'historique
     * des dates de mandat, la période est bornée par son premier et son dernier
     * vote — c'est la définition déjà portée sur la page d'un député.
     *
     * Un seul balayage de `vote` sert les deux classements ; les dénominateurs
     * se déduisent ensuite des dates de scrutin, tenues en mémoire.
     *
     * Les taux obtenus ne recouvrent pas exactement ceux de datan.fr, et il ne
     * faut pas chercher à les y ramener : sur les 574 députés classés de part et
     * d'autre, l'écart moyen est de −1,8 point, 81 taux coïncident à un demi-point
     * près et 19 s'écartent de plus de 20 points. Les deux dénominateurs ne
     * partent pas de la même période d'activité — l'Assemblée date les mandats
     * autrement que `daily.php`, qui les recompose. Les extrêmes sont d'ailleurs
     * du bon côté : là où la production affiche 100 % à des députés entrés en
     * cours de législature et n'ayant voté que quatre à dix-neuf fois, le
     * dénominateur retenu ici rapporte leurs votes à tous les solennels tenus
     * pendant qu'ils siégeaient.
     *
     * @return array<string, list<array{id: int, score: float, numerateur: int, denominateur: int, tri: array<int|string>}>>
     */
    private function participationDeputes(int $legislature): array
    {
        $dates = [
            TypeClassement::DeputesParticipation->value => $this->datesScrutins($legislature, self::SOLENNEL),
            TypeClassement::DeputesParticipationTous->value => $this->datesScrutins($legislature, null),
        ];

        $activite = $this->connection->fetchAllAssociative(
            'SELECT v.depute_id,
                    MIN(v.scrutin_date) AS debut,
                    MAX(v.scrutin_date) AS fin,
                    SUM(s.code_type_vote = :solennel AND v.position IN ' . self::EXPRIMES . ') AS exprimes_solennels,
                    SUM(s.code_type_vote <> :motion AND v.position IN ' . self::EXPRIMES . ') AS exprimes_tous
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             WHERE v.vote_type = :type AND s.legislature = :legislature
             GROUP BY v.depute_id',
            [
                'solennel' => self::SOLENNEL,
                'motion' => self::MOTION_CENSURE,
                'type' => self::OFFICIEL,
                'legislature' => $legislature,
            ],
        );

        $deputes = $this->deputesEnExercice($legislature);
        $classements = [];

        foreach ([
            TypeClassement::DeputesParticipation->value => 'exprimes_solennels',
            TypeClassement::DeputesParticipationTous->value => 'exprimes_tous',
        ] as $type => $colonne) {
            $classement = [];

            foreach ($activite as $ligne) {
                $deputeId = (int) $ligne['depute_id'];
                if (!isset($deputes[$deputeId])) {
                    continue;
                }

                $tenus = $this->comptePeriode($dates[$type], $ligne['debut'], $ligne['fin']);
                if ($tenus === 0) {
                    continue;
                }

                $exprimes = (int) $ligne[$colonne];

                $classement[] = [
                    'id' => $deputeId,
                    'score' => $exprimes / $tenus,
                    'numerateur' => $exprimes,
                    'denominateur' => $tenus,
                    'tri' => [-$exprimes, $deputes[$deputeId]],
                ];
            }

            $classements[$type] = $classement;
        }

        return $classements;
    }

    /**
     * Part des votes exprimés conformes à la position majoritaire du groupe,
     * relevée scrutin par scrutin dans la ventilation historique `vote_groupe`.
     *
     * Seuls comptent les votes émis depuis que le député siège dans le groupe
     * où il se trouve aujourd'hui. La page d'un député, faute d'historique des
     * rattachements au moment où elle a été écrite, confronte encore tous ses
     * votes à son groupe actuel ; `fonction_groupe` permet désormais de dater
     * son arrivée, et c'est ainsi que procédait l'application d'origine, qui
     * tenait un score par mandat de groupe. Sans cette borne, un député passé
     * du RN aux non-inscrits voit ses années de votes RN comparées à la ligne
     * des non-inscrits, et son taux s'effondre sans raison.
     *
     * @return list<array{id: int, score: float, numerateur: int, denominateur: int, tri: array<int|string>}>
     */
    private function loyauteDeputes(int $legislature): array
    {
        $deputes = $this->deputesEnExercice($legislature);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.depute_id,
                    COUNT(*) AS total,
                    SUM(v.position = vg.position_majoritaire) AS conformes
             FROM vote v
             JOIN scrutin s ON s.id = v.scrutin_id
             JOIN depute d ON d.id = v.depute_id
             JOIN vote_groupe vg ON vg.scrutin_id = v.scrutin_id AND vg.groupe_id = d.groupe_id
             JOIN (SELECT depute_id, groupe_id, MAX(date_debut) AS depuis
                   FROM fonction_groupe
                   WHERE date_fin IS NULL
                   GROUP BY depute_id, groupe_id) f
               ON f.depute_id = v.depute_id AND f.groupe_id = d.groupe_id
             WHERE v.vote_type = :type AND s.legislature = :legislature
               AND v.position IN ' . self::EXPRIMES . '
               AND v.scrutin_date >= f.depuis
             GROUP BY v.depute_id',
            ['type' => self::OFFICIEL, 'legislature' => $legislature],
        );

        $classement = [];

        foreach ($rows as $ligne) {
            $deputeId = (int) $ligne['depute_id'];
            $total = (int) $ligne['total'];

            if (!isset($deputes[$deputeId]) || $total === 0) {
                continue;
            }

            $conformes = (int) $ligne['conformes'];

            $classement[] = [
                'id' => $deputeId,
                'score' => $conformes / $total,
                'numerateur' => $conformes,
                'denominateur' => $total,
                'tri' => [-$total, $deputes[$deputeId]],
            ];
        }

        return $classement;
    }

    /**
     * Âge des députés en années révolues, du plus âgé au plus jeune.
     *
     * L'âge se recalcule à partir de la date de naissance plutôt que de lire
     * `depute.age`, figé à la date de son import : deux députés du même âge se
     * départagent ainsi à la date près, comme sur le site d'origine.
     *
     * @return list<array{id: int, score: float, numerateur: null, denominateur: null, tri: array<int|string>}>
     */
    private function ageDeputes(int $legislature): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.id, ps.date_naissance
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND ps.date_naissance IS NOT NULL
               AND ' . sprintf(self::EN_EXERCICE, 'd'),
            ['legislature' => $legislature],
        );

        $classement = [];

        foreach ($rows as $ligne) {
            $naissance = new \DateTimeImmutable($ligne['date_naissance']);

            $classement[] = [
                'id' => (int) $ligne['id'],
                'score' => (float) $naissance->diff($this->aujourdhui)->y,
                'numerateur' => null,
                'denominateur' => null,
                // À âge égal, l'aîné passe devant : le tri secondaire est la date de naissance.
                'tri' => [$naissance->format('Y-m-d')],
            ];
        }

        return $classement;
    }

    /**
     * Cohésion moyenne des groupes : l'indice d'accord de leurs ventilations de
     * scrutin, moyenné sur toute la législature.
     *
     * @return list<array{id: int, score: float, numerateur: null, denominateur: int, tri: array<int|string>}>
     */
    private function cohesionGroupes(int $legislature): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id,
                    AVG(' . self::COHESION_SQL . ') AS cohesion,
                    COUNT(*) AS scrutins
             FROM vote_groupe vg
             JOIN groupe g ON g.id = vg.groupe_id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL
             GROUP BY g.id
             HAVING cohesion IS NOT NULL',
            ['legislature' => $legislature],
        );

        return $this->classementGroupes($rows, static fn (array $l) => [
            'score' => (float) $l['cohesion'],
            'numerateur' => null,
            'denominateur' => (int) $l['scrutins'],
        ]);
    }

    /**
     * Taux de participation moyen des groupes : par scrutin, la part des membres
     * qui se sont exprimés. Les non-votants — présidence de séance, membres du
     * Gouvernement — sortent du dénominateur, n'ayant pas le droit de voter.
     *
     * @return list<array{id: int, score: float, numerateur: null, denominateur: int, tri: array<int|string>}>
     */
    private function participationGroupes(int $legislature, bool $solennelsSeuls): array
    {
        $filtre = $solennelsSeuls
            ? 's.code_type_vote = :solennel'
            : '(s.code_type_vote IS NULL OR s.code_type_vote <> :motion)';

        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id,
                    AVG((vg.nombre_pours + vg.nombre_contres + vg.nombre_abstentions)
                        / NULLIF(vg.nombre_membres_groupe - vg.non_votants, 0)) AS taux,
                    COUNT(*) AS scrutins
             FROM vote_groupe vg
             JOIN groupe g ON g.id = vg.groupe_id
             JOIN scrutin s ON s.id = vg.scrutin_id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL AND ' . $filtre . '
             GROUP BY g.id
             HAVING taux IS NOT NULL',
            $solennelsSeuls
                ? ['legislature' => $legislature, 'solennel' => self::SOLENNEL]
                : ['legislature' => $legislature, 'motion' => self::MOTION_CENSURE],
        );

        return $this->classementGroupes($rows, static fn (array $l) => [
            'score' => (float) $l['taux'],
            'numerateur' => null,
            'denominateur' => (int) $l['scrutins'],
        ]);
    }

    /**
     * @return list<array{id: int, score: float, numerateur: null, denominateur: int, tri: array<int|string>}>
     */
    private function ageGroupes(int $legislature): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id,
                    AVG(TIMESTAMPDIFF(YEAR, ps.date_naissance, :aujourdhui)) AS age,
                    COUNT(*) AS effectif
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL AND g.libelle_abrev <> :ni
               AND ps.date_naissance IS NOT NULL AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY g.id',
            [
                'legislature' => $legislature,
                'ni' => self::NON_INSCRITS,
                'aujourdhui' => $this->aujourdhui->format('Y-m-d'),
            ],
        );

        return $this->classementGroupes($rows, static fn (array $l) => [
            'score' => (float) $l['age'],
            'numerateur' => null,
            'denominateur' => (int) $l['effectif'],
        ]);
    }

    /**
     * @return list<array{id: int, score: float, numerateur: int, denominateur: int, tri: array<int|string>}>
     */
    private function feminisationGroupes(int $legislature): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id,
                    COUNT(*) AS effectif,
                    SUM(d.civilite = :mme) AS femmes
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL AND g.libelle_abrev <> :ni
               AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY g.id',
            ['legislature' => $legislature, 'ni' => self::NON_INSCRITS, 'mme' => 'Mme'],
        );

        return $this->classementGroupes($rows, static fn (array $l) => [
            'score' => (int) $l['femmes'] / (int) $l['effectif'],
            'numerateur' => (int) $l['femmes'],
            'denominateur' => (int) $l['effectif'],
        ]);
    }

    /**
     * Indice de Rose : à quel point la composition sociale d'un groupe reflète
     * celle du pays. Un groupe parfaitement représentatif vaut 1, un groupe qui
     * n'a aucun point commun avec la population vaut 0.
     *
     * Formule de l'application d'origine : 1 − ½ Σ |part dans la population −
     * part dans le groupe|, sur les huit familles socio-professionnelles de
     * l'INSEE. Les députés dont la profession n'est pas déclarée sortent du
     * calcul faute de pouvoir être rangés.
     *
     * @return list<array{id: int, score: float, numerateur: null, denominateur: int, tri: array<int|string>}>
     */
    private function origineSocialeGroupes(int $legislature): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id, ps.fam_soc_pro, COUNT(*) AS n
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             JOIN profil_social ps ON ps.depute_id = d.id
             WHERE g.legislature = :legislature AND g.date_fin IS NULL AND g.libelle_abrev <> :ni
               AND ps.fam_soc_pro IS NOT NULL AND ' . sprintf(self::EN_EXERCICE, 'd') . '
             GROUP BY g.id, ps.fam_soc_pro',
            ['legislature' => $legislature, 'ni' => self::NON_INSCRITS],
        );

        /** @var array<int, array<string, int>> $parGroupe */
        $parGroupe = [];
        foreach ($rows as $ligne) {
            $parGroupe[(int) $ligne['id']][$ligne['fam_soc_pro']] = (int) $ligne['n'];
        }

        $population = FamilleSocioPro::population();
        $agreges = [];

        foreach ($parGroupe as $groupeId => $effectifs) {
            $classes = array_sum($effectifs);
            $ecart = 0.0;

            foreach ($population as $famille => $part) {
                $ecart += abs($part / 100 - ($effectifs[$famille] ?? 0) / $classes);
            }

            $agreges[] = ['id' => $groupeId, 'rose' => 1 - 0.5 * $ecart, 'classes' => $classes];
        }

        return $this->classementGroupes($agreges, static fn (array $l) => [
            'score' => (float) $l['rose'],
            'numerateur' => null,
            'denominateur' => (int) $l['classes'],
        ]);
    }

    /**
     * Complète des agrégats par groupe de leur clé de tri secondaire : à score
     * égal, le groupe le plus nombreux passe devant, puis l'ordre alphabétique.
     * L'application d'origine tranchait au hasard, ce qui faisait bouger le
     * classement d'un affichage à l'autre.
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): array{score: float, numerateur: int|null, denominateur: int|null} $valeurs
     *
     * @return list<array{id: int, score: float, numerateur: int|null, denominateur: int|null, tri: array<int|string>}>
     */
    private function classementGroupes(array $rows, callable $valeurs): array
    {
        $this->sigles ??= $this->connection->fetchAllKeyValue('SELECT id, libelle_abrev FROM groupe');
        $this->effectifs ??= $this->connection->fetchAllKeyValue(
            'SELECT d.groupe_id, COUNT(*)
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             WHERE EXISTS (SELECT 1 FROM mandat m
                           WHERE m.depute_id = d.id AND m.legislature = g.legislature
                             AND m.date_fin IS NULL)
             GROUP BY d.groupe_id',
        );

        $classement = [];

        foreach ($rows as $ligne) {
            $groupeId = (int) $ligne['id'];

            $classement[] = $valeurs($ligne) + [
                'id' => $groupeId,
                'tri' => [-(int) ($this->effectifs[$groupeId] ?? 0), $this->sigles[$groupeId] ?? ''],
            ];
        }

        return $classement;
    }

    /**
     * Trie un classement par score décroissant, attribue les rangs et réécrit la
     * table pour ce type et cette législature. Deux scores égaux partagent le
     * même rang et décalent le suivant d'autant, comme le `RANK()` d'origine.
     *
     * @param list<array{id: int, score: float, numerateur: int|null, denominateur: int|null, tri: array<int|string>}> $classement
     */
    private function remplace(TypeClassement $type, int $legislature, array $classement): void
    {
        // Score décroissant ; à égalité, la clé de tri secondaire, construite
        // pour être croissante (d'où les compteurs comptés en négatif).
        usort(
            $classement,
            static fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: ($a['tri'] <=> $b['tri']),
        );

        $this->connection->executeStatement(
            'DELETE FROM classement WHERE type = :type AND legislature = :legislature',
            ['type' => $type->value, 'legislature' => $legislature],
        );

        $colonne = $type->porteSurUnGroupe() ? 'groupe_id' : 'depute_id';
        $calculeLe = $this->aujourdhui->format('Y-m-d');

        $rang = 0;
        $precedent = null;
        $batch = [];

        foreach ($classement as $position => $ligne) {
            if ($precedent === null || abs($ligne['score'] - $precedent) > 1e-9) {
                $rang = $position + 1;
                $precedent = $ligne['score'];
            }

            $batch[] = [
                $type->value,
                $legislature,
                $rang,
                $ligne['id'],
                round($ligne['score'], 3),
                $ligne['numerateur'],
                $ligne['denominateur'],
                $calculeLe,
            ];

            if (\count($batch) >= self::BATCH_SIZE) {
                $this->insere($colonne, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->insere($colonne, $batch);
        }
    }

    /**
     * @param list<list<mixed>> $batch
     */
    private function insere(string $colonne, array $batch): void
    {
        $placeholders = implode(', ', array_fill(0, \count($batch), '(?, ?, ?, ?, ?, ?, ?, ?)'));

        $this->connection->executeStatement(
            'INSERT INTO classement (type, legislature, rang, ' . $colonne . ',
                                     score, numerateur, denominateur, calcule_le)
             VALUES ' . $placeholders,
            array_merge(...$batch)
        );
    }

    /**
     * Dates de tous les scrutins de la législature, triées, éventuellement
     * restreintes à un type de scrutin. Les motions de censure sont écartées
     * quand on regarde l'ensemble des scrutins.
     *
     * @return list<string>
     */
    private function datesScrutins(int $legislature, ?string $codeTypeVote): array
    {
        if ($codeTypeVote !== null) {
            return $this->connection->fetchFirstColumn(
                'SELECT date_scrutin FROM scrutin
                 WHERE legislature = :legislature AND code_type_vote = :code
                 ORDER BY date_scrutin',
                ['legislature' => $legislature, 'code' => $codeTypeVote],
            );
        }

        return $this->connection->fetchFirstColumn(
            'SELECT date_scrutin FROM scrutin
             WHERE legislature = :legislature
               AND (code_type_vote IS NULL OR code_type_vote <> :motion)
             ORDER BY date_scrutin',
            ['legislature' => $legislature, 'motion' => self::MOTION_CENSURE],
        );
    }

    /**
     * Nombre de scrutins tenus entre deux dates incluses. Les dates étant
     * triées, deux recherches dichotomiques suffisent — ce compte est repris
     * pour chacun des 577 députés sur plusieurs milliers de scrutins.
     *
     * @param list<string> $dates
     */
    private function comptePeriode(array $dates, string $debut, string $fin): int
    {
        return $this->borne($dates, $fin, true) - $this->borne($dates, $debut, false);
    }

    /**
     * Position d'insertion de `$valeur` dans `$dates` : après ses occurrences
     * si `$inclusive`, avant sinon.
     *
     * @param list<string> $dates
     */
    private function borne(array $dates, string $valeur, bool $inclusive): int
    {
        $bas = 0;
        $haut = \count($dates);

        while ($bas < $haut) {
            $milieu = intdiv($bas + $haut, 2);

            if ($inclusive ? $dates[$milieu] <= $valeur : $dates[$milieu] < $valeur) {
                $bas = $milieu + 1;
            } else {
                $haut = $milieu;
            }
        }

        return $bas;
    }

    /**
     * Députés en exercice, associés à leur nom : ceux rattachés à un groupe de
     * la législature demandée. Le nom sert de dernier départage au tri.
     *
     * @return array<int, string>
     */
    private function deputesEnExercice(int $legislature): array
    {
        return $this->connection->fetchAllKeyValue(
            'SELECT d.id, CONCAT(d.lastname, " ", d.firstname)
             FROM depute d
             JOIN groupe g ON g.id = d.groupe_id
             WHERE g.legislature = :legislature AND ' . sprintf(self::EN_EXERCICE, 'd'),
            ['legislature' => $legislature],
        );
    }
}
