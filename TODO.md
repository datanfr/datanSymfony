# TODO — plan de correction issu de la comparaison datan.fr ↔ datan.remikel.fr (2026-08-08)

Campagne de comparaison complète : diff des 14 sitemaps (~40 000 URL), comparaison
HTML de 10 sections, captures pleine page de 6 pages clés, mesures de temps de
réponse. Verdict : portage très fidèle, performance équivalente. Reste ce qui suit,
classé par priorité. Chaque point dit **quoi**, **où chercher**, et **comment vérifier**.

Rappel de méthode : datan.fr est derrière une protection anti-rafale (429 o2switch) —
toute vérification côté legacy se rejoue **une URL à la fois**. Une section « vide »
sur une capture headless peut n'être qu'un artefact de `loading="lazy"` : vérifier le
HTML avant de conclure.

---

## P0 — Contrat d'URL (bloquant : des URL indexées rendent 404)

- [x] **Commune « Faux » (Dordogne) importée sous le slug « 0 ».** — *Fait le
  2026-08-08.* La corruption venait du **dump** (les deux communes « Faux »,
  08165 et 24177, y portent `commune_nom = '0'` : « Faux » est le libellé
  français du booléen FALSE, converti par un aller-retour tableur ; la vraie
  prod sert bien `ville_faux`). Corrigé aux trois niveaux : requête d'export
  auto-réparante via `cities.nom_standard` (docblock d'`ImportCommunesCommand`),
  garde dans l'import qui refuse toute ligne sans lettre (avec bilan chiffré),
  données locales réparées, TSV régénéré et import rejoué. Vérifié : sitemap
  local annonce `ville_faux`, plus aucun `ville_0`, 0 commune corrompue en base.
  **Reste : redéployer + rejouer l'import sur le staging.**

- [x] **Slugs de communes à article parenthésé.** — *Fait le 2026-08-08, sans
  301 : alignement des données sur les adresses que la prod sert.* Recensement
  complet des 24 communes à slug parenthésé : seules **Étoile (L')** (39217 →
  `etoile`) et **Nonières (Les)** (07165 → `nonieres`) divergent — la prod a
  nettoyé ces deux slugs après la prise de notre dump (vérifié sur les pages
  département de datan.fr, qui font foi). `CASE` posé dans la requête d'export
  (docblock d'`ImportCommunesCommand`), données alignées, sitemap vérifié.
  Assions, Ollières-sur-Eyrieux et Bonvillers gardent leurs parenthèses (la prod
  les lie ainsi mais les refuse en 400 — aucun contrat à honorer) ; les 19
  autres ne sont liées nulle part sur datan.fr.
  **Reste : redéployer + rejouer l'import sur le staging.**

## P1 — Fiches vote : title et casse du h1 (18 311 pages, SEO)

- [x] **Reprendre le gabarit `<title>` du legacy.** — *Fait le 2026-08-08.*
  Nouvelle classe `App\TitreMeta` : portage du CASE SQL de
  `Votes_model::get_individual_vote()` (Vote final / Motion de renvoi /
  Amendement n°X / Article n°X + titre du dossier), avec extraction des numéros
  d'amendement et d'article depuis le libellé comme `daily.php:1788-1810` (les
  colonnes `amdt`/`article` n'ont pas été portées en base). La meta description
  reprend aussi le format legacy (« Découvrez le vote des députés sur le
  scrutin : … », variante explication de vote). Vérifié identique au caractère
  près sur : vote_1 L16 (Motion de censure), vote_100 L15 (amendement sans
  dossier), vote_997 L17 (Vote final), vote_8426 (Amendement n°1),
  vote_8415 (Article n°13), vote_c1 L16 (Congrès).
- [x] **Retirer le `|capitalize` du h1.** — *Fait le 2026-08-08.* Filtre Twig
  `ucfirst` ajouté à `DatanExtension` (première lettre seulement, multi-octets),
  appliqué au h1 et au « Type de vote » de l'encart Infos. « La NUPES »,
  « M. Aviragnet », « Élisabeth Borne » gardent leurs majuscules ; vote_997
  (déjà tout en minuscules) inchangé.

## P1 — Données de production en retard ou non portées

**Source découverte le 2026-08-08** : le jeu public
`datan.fr/assets/dataset_backup/general/latest.sql` (195 Mo, daté du 31 juillet
2026) est bien plus complet que ne le disait `CLAUDE.md` — il porte
`cities_mayors` en entier (34 874 lignes), les 240 décryptages publiés et 47
explications. Chargé dans le conteneur (`datan_backup`), il a servi à tous les
rattrapages ci-dessous. Rester prudent : c'est un instantané au 31 juillet et
les comptes y sont anonymisés — **au déploiement, tout se rejoue contre la
vraie base**.

- [x] **Explications de vote rattrapées.** — *Fait le 2026-08-08.* 47 importées
  (42 publiées, contre 40 avant). Export TSV régénéré depuis le jeu public.
  Reste l'écart de fraîcheur : la prod du 8 août en a de plus récentes que
  l'instantané du 31 juillet, **à rejouer au déploiement**.
- [x] **Décryptages rattrapés.** — *Fait le 2026-08-08.* Les 2 manquants
  (acétamipride 8427, aide à mourir 8280) sont importés : 252 en base, **240
  publiés — le compte exact du legacy**. Vérifié : `/votes/decryptes` affiche
  « 62 votes… » et 12 vignettes Agriculture, identiques à datan.fr ; l'accueil
  et les carrousels ressortent vote_8427 et vote_8280.
  *Méthode* : `ImportDecryptagesCommand` attend une connexion PDO et le
  conteneur refuse les connexions hors localhost ; les trois tables source
  (`votes_datan`, `fields`, `readings`) ont donc été chargées temporairement
  dans `datan_symfony`, l'import lancé dessus, puis les tables supprimées
  (schéma revérifié « in sync »).
- [x] **Table des maires portée.** — *Fait le 2026-08-08.* Elle n'était pas
  perdue, seulement vide dans la copie de travail. Migration
  `Version20260808090000` (3 colonnes sur `commune`), import via
  `app:import:communes --maires` (requête d'export au docblock), **34 865
  communes avec maire**. Affichage rétabli aux deux endroits : « Le maire
  d'Ajaccio est Stéphane Sbraggia. » sur les fiches de ville et la ligne
  « 🏛️ Maire » de l'encadré des pages résultats. L'élision est corrigée
  (`ville.de`) par cohérence avec le reste de la page. La correction manuelle du
  legacy (Berre-l'Étang, SALVO → Doriol) n'est pas portée : son référentiel a
  été mis à jour depuis et elle ne se déclenche plus.
- [x] **Images in-article du blog récupérées.** — *Fait le 2026-08-08.*
  17 fichiers rapatriés sous `public/assets/imgs/posts/inside/`, sous-dossiers
  compris (`2025_classement/`, `interview_lebras/`) — recensés en cherchant les
  URL dans le corps des articles, pas seulement celles d'un article. Tous
  répondent 200 en local.
  - [ ] **Reste** : les corps d'articles référencent ces images en **absolu**
    vers `https://datan.fr`. Tant que le domaine ne bascule pas, elles se
    chargent depuis la prod ; à la bascule, elles se chargeront des fichiers
    locaux. Vérifier ce jour-là. (16 articles portent des liens absolus
    `datan.fr`, images et liens internes confondus.)
- [ ] **Porter la section « Ses professions de foi » des fiches député** (tableaux
  législatives 2024/2022, boutons « Profession 1er/2nd tour »). Section entière
  absente du staging (~460 px). Vérifier d'où le legacy tire les fichiers PDF/liens
  — regarder du côté de `datan_backup` (le jeu public a peut-être la table).

## P1 — Statistiques : écarts de calcul à instruire un par un

Pour chacun : comprendre la règle du legacy (la réponse est dans son code, souvent
`daily.php`), puis trancher parité/correction et **commenter le choix** dans le code.

- [ ] **Participation moyenne 89 % (legacy) vs 90 % (staging)** — même total de
  8 434 scrutins annoncé des deux côtés, donc écart de calcul, pas de fraîcheur.
  Lié au point suivant.
- [ ] **Dénominateur « nombre de votes » du tableau « Tous les votes »** : uniforme
  à 8 411 côté staging pour un député présent toute la législature, variable côté
  legacy (8 402 / 8 405 / 8 408 selon le député). Trouver ce que le legacy retranche
  (scrutins pendant la période d'activité ? exclusions ponctuelles ?) avant de
  décider qui a raison. 568 lignes sur 576 diffèrent, 5 pourcentages bougent de ±1 pt.
- [ ] **Tableau « Votes par spécialisation »** : legacy 583 lignes (9 députés en
  double — une ligne par commission —, Braun-Pivet absente), staging 575 (dédoublonné,
  Braun-Pivet réintégrée) ; 463 pourcentages sur 574 diffèrent, certains massivement
  (Clémence Guetté 89 % → 25 %). Deux causes à séparer :
  1. le dédoublonnage (commission dominante) — probablement une correction à assumer ;
  2. le périmètre « votes liés à la commission », systématiquement plus large côté
     staging (298→308, 1422→1602, 1774→2369…) : le legacy scrape le lien
     scrutin↔dossier sur assemblee-nationale.fr (couverture partielle). Documenter la
     source de chaque côté et choisir.
- [ ] **Graphique « évolution du taux de députées femmes »** : le staging supprime la
  barre 1997-02 et scinde 2022-27 en 2022-24 (37 %) / 2024-29 (38 %), ce qui inverse
  la phrase dynamique (« a légèrement baissé » → « augmenté »). Le libellé legacy
  « 2022-27 » est faux depuis la dissolution, mais l'écart visuel est important :
  trancher, et si le découpage staging est gardé, le commenter comme correction.
- [ ] **Représentativité sociale** : LIOT 0.107 → 0,142 ; LFI-NFP 0.432 → 0,446 ;
  et sur la fiche DEM la catégorie à 0 % diffère (« ouvriers, 11 % » vs « personnes
  n'ayant jamais travaillé, 13 % »). Comparer la nomenclature des catégories et le
  calcul de l'indice.
- [ ] **Cohésion** : moyenne 0.927 → 0,925 ; DR 0.899 → 0,898 (les 11 autres groupes
  strictement identiques — probable écart d'arrondi ou de périmètre marginal).
- [ ] **Avertissement Covid-19** (« Attention, à cause de la crise de la Covid-19… »)
  supprimé côté staging : probablement obsolète, mais décider et documenter.
- [ ] **Départage des ex æquo** (top 3 participation à 100 %, carte « Vote le plus »,
  fins de classement) : le legacy a-t-il un tri secondaire reproductible ? Si oui le
  reproduire, sinon en poser un déterministe et le commenter.

## P1 — Page /elections et pages résultats

- [x] **Bug de gabarit des cartes d'élection.** — *Fait le 2026-08-08.* Deux
  causes dans `ElectionRepository` : `SUM(c.elu = 1)` rendait NULL quand `elu`
  n'est renseigné nulle part (présidentielle, municipales) → `COALESCE(…, 0)`
  comme le `SUM(CASE … ELSE 0)` du legacy ; et l'état était déduit des dates
  alors que le legacy le **fige à la main** par élection
  (`get_election_state()`) — les municipales 2026, passées mais non dépouillées,
  basculaient à tort sur le décompte d'élus. État figé porté (1-6 → achevé,
  7 → attendu, défaut attendu). Vérifié : « 337 députés candidats »
  (municipales), « 0 député élu » (présidentielle), 1/422/292/47 élus, pied
  vide des départementales — tout identique au legacy.
- [ ] **Section « Les prochaines élections en France » absente** du staging.
  Le texte legacy est périmé (dates de mars 2026 au futur) : reconstruire la section
  avec un contenu juste plutôt que la laisser tomber.
- [ ] **Second tour d'Ambérieu-en-Bugey (législatives 2024)** : le legacy affiche
  « pas de second tour », le staging des résultats complets (Chavent 51,27 % /
  Pisani 48,73 %). Chavent n'avait que 36,47 % au T1 : le staging semble avoir
  raison. Vérifier contre la source, puis commenter comme correction d'un défaut de
  données du legacy (ou corriger si c'est l'inverse).
- [ ] **Top 30 des plus grandes communes** : le staging insère Saint-Denis de La
  Réunion (20e), le legacy l'exclut. Retrouver le critère legacy (exclusion
  outre-mer ? population différente ?) et s'aligner ou assumer.
- [ ] **Casse des noms de candidats** : legacy « Marc CHAVENT », staging
  « Marc Chavent ». Choix d'affichage à trancher (parité = capitales).
- [ ] **Habillage municipales 2026 des pages résultats** (title/h1/meta « Élections
  municipales 2026 dans l'Ain ») : différence assumée tant que les municipales ne
  sont pas portées — à réévaluer si le périmètre change. Rien à faire pour l'instant.

## P2 — Sitemaps

- [ ] **Ajouter au sitemap structure les 7 pages fixes manquantes** : `/a-propos`,
  `/faq`, `/mentions-legales`, `/soutenir`, `/parrainages-2022`,
  `/outils/coalition-simulateur`, `/blog` (toutes répondent 200 ; le legacy les
  annonce ; le site vit de son référencement).
- [ ] **Zéro-padder les mois des archives** dans le sitemap : annoncer
  `/votes/legislature-17/2026/07`, pas `/2026/7`. Les deux formes répondent 200 sans
  canonical commun → au passage, faire pointer le canonical des deux formes vers la
  forme zéro-paddée (celle indexée depuis des années).
- [ ] **`ville_0` disparaîtra du sitemap** avec la correction P0 — vérifier.
- [ ] Extensions assumées du sitemap staging (groupes NI, `/commissions`,
  `/legislature-N`, inactifs L14-16, `/votes/all` retirés des inactifs) : rien à
  faire, mais vérifier par échantillon que tout ce qui est annoncé répond 200.

## P2 — Lot de gabarits — *fait le 2026-08-08*

- [x] **Boutons « Voir les derniers votes » / « Tous les votes »** de l'accueil :
  repointés sur `/votes` (le legacy) au lieu de `/votes/legislature-17`.
- [x] **Meta description des archives mensuelles** : les trois formulations du
  legacy sont portées (mois, année, législature) — « en juin 2024 » revient, et
  les variantes courtes ne mentionnent plus la participation, comme chez lui.
- [x] **Tooltip de l'hémicycle** : « Députés non inscrits (NI) », via le
  `CASE WHEN libelle = 'Non inscrit'` que le legacy applique dans tout son
  `Groupes_model`.
- [x] **Camembert de la fiche vote** : étiquette « 113 » supprimée
  (`datalabels: {display: false}`, que le legacy pose explicitement) et jaune
  aligné sur le sien (#FFBA49, distinct du #FFAD29 du bandeau).
- [x] **Boutons « Le dossier » / « L'amendement »** : icône de lien externe
  rétablie, et liens passés par `url_obf` comme le legacy — ces sorties vers
  assemblee-nationale.fr étaient exposées en clair aux robots.
  Variante mobile du bloc « En savoir plus » ajoutée (elle manquait).
- [x] **Bulle d'aide « ? »** du titre « Les coalitions les plus fréquentes »
  rétablie (le legacy y réutilise le popover du taux de proximité).
- [x] **Badges de coalition** rangés par effectif décroissant (RN, EPR, DR,
  DEM…) et non alphabétiquement — c'est le `uasort` de `format_coalitions()`.
  L'effectif se compte sur `fonction_groupe`, valable aussi pour un groupe
  dissous.
- [x] **« Polynésie Française » → « Polynésie française »** : l'Assemblée
  surcapitalise l'adjectif, la table `departement` du site a la bonne graphie —
  exception posée dans l'import, qui préfère sinon l'orthographe de l'Assemblée.
- [x] **Ordre des logos de « La position des groupes »** : conservé par effectif
  décroissant, **divergence assumée et commentée** — la requête du legacy n'a
  aucun `ORDER BY` et son ordre est celui, arbitraire, des lignes de sa table
  `organes` (vérifié). Un accident de stockage ne se reproduit pas.

## P2 — Typographie et casse transverses

- [ ] **Séparateur décimal** : le staging affiche la virgule (0,86 ; 4,4 %) là où le
  legacy affiche le point (0.86 ; 4.4 %) — systématique (statistiques, cohésion,
  évolutions de population). La virgule est le bon usage français mais s'écarte de la
  parité : trancher une fois pour tout le site et commenter. (Incohérence actuelle :
  « 51.7 ans » garde le point des deux côtés.)
- [x] Capitalisations gardées (« Val-d'Oise », « Côtes-d'Armor », « 2A »/« 2B ») :
  corrections assumées du legacy — commentées dans `ImportCommunesCommand`.

## P2 — Divers pages et gabarits

- [ ] **Section campagne de dons (`#campaign`)** : inclusion incohérente — absente de
  `/votes` côté staging (présente côté legacy), présente sur les pages mois côté
  staging (absente côté legacy), position différente sur les pages commune. Décider
  la liste des pages porteuses et l'emplacement, puis harmoniser.
- [ ] **h2 de `/votes`** : « Derniers votes non-decryptés de l'Assemblée nationale »
  (legacy, inexact et coquillé) vs « Les derniers votes de l'Assemblée nationale »
  (staging). La reformulation est défendable : garder, mais commenter pourquoi.
- [ ] **Placeholder des photos de députés** pendant le chargement : pictogramme
  « personne » (legacy) vs cercle dégradé vide (staging). Cosmétique, visible sur
  les grilles.
- [ ] **Position du % gagnant** dans l'encadré « Son élection » (fiche député) :
  aligné différemment ; et le lien « Consultez les résultats complets » n'est plus
  souligné. Deux détails de CSS à reprendre.
- [ ] **Icône « Proximité avec son groupe »** (poignée de main) : graisse différente.
- [ ] **Liste « Les autres députés RN »** de la fiche Buisson : sélection différente
  (legacy : Barthès, Bordes ; staging : Bovet, Casterman). Retrouver le critère de
  sélection du legacy (aléatoire ? alphabétique ? même département ?).
- [ ] **« Il vote rarement avec »** (fiche Buisson) : trois groupes ex æquo à 19 %,
  le texte legacy cite ECOS, le staging LFI-NFP. Même chantier que le départage
  d'ex æquo des statistiques.
- [ ] **Fiche groupe DEM, texte de présentation** : le staging a un paragraphe
  enrichi absent du legacy, terminé par un « .. » (double point). Corriger la
  ponctuation ; décider si le texte enrichi reste (il vient probablement de la base
  de rédaction — dans ce cas c'est le legacy qui est en retard).
- [ ] **Candidats municipales fiche DEM** : « 21 députés candidats » (legacy) vs
  « 18 » (staging). Même famille que les données municipales non importées :
  vérifier la source du compteur.

## P2 — Performance et SEO techniques

- [ ] **Remettre les `<link rel=preload>`** des images de couverture
  (`/assets/imgs/cover/hemicycle-front{-375,-768,}.jpg`) sur les pages qui les
  avaient (département, a-propos, mentions-legales, soutenir…) : LCP.
- [ ] **Alt des vignettes blog** : « Image post 15 » vs « Image post 26 » — l'ID
  auto-incrémenté diffère entre bases. Sans gravité, mais un alt parlant (titre de
  l'article) réglerait la question mieux que la parité.
- [ ] **Troncature meta description des articles** : coupe un mot plus tôt que le
  legacy (l'entité `&nbsp;` compte 6 caractères chez lui). À ignorer sauf si l'on
  vise l'octet près.

## Checklist de bascule (rien à coder, à dérouler le jour J)

- [ ] Rejouer les imports de récupération contre la **vraie** base de production
  (comptes, explications, décryptages, blog) — jamais depuis le backup anonymisé.
- [ ] Rebrancher **Matomo** (matomo.datan.fr, siteId 1) et **GTM** (GTM-K3QQNK2) —
  absents du staging, vraisemblablement à dessein.
- [ ] Vérifier que canonical/og:url basculent bien sur datan.fr (générés depuis
  l'hôte : automatique, mais vérifier).
- [ ] Vérifier les images `posts/inside/` (cf. P1) une fois le domaine basculé.
- [ ] Rejouer une passe de vérification du sitemap : chaque URL annoncée → 200
  (le critère de `SitemapController` se vérifie en interrogeant réellement).

## Différences assumées — ne PAS « corriger » (consignées pour mémoire)

- Participation des groupes sur la fiche vote (DR 15→17 %, EPR 13→14 %) : correction
  documentée du bug legacy des non-votants (`App\Groupe\ParticipationGroupe`).
- Badges d'explication de vote « -1/1 » → « POUR/CONTRE » stylés (le legacy rendait
  la valeur brute non stylée).
- Coquilles corrigées (« Découvez », « présiedent », « vigeur », « consenement »,
  « Assemblée national », « ralantissant », mojibake « JÃ©rÃ´me »…), élisions
  (« d'Ajaccio »), « 15ème législature » figée, h1 vide des Français de l'étranger,
  « 0 député » affiché, année de copyright du login.
- Liens morts legacy remplacés : `/cookiespolicy` → `/mentions-legales`,
  `recherche.php` → géré en JS, rubriques blog « rapports » en dur → vraie rubrique,
  phrase « cliquez ici » à `href="#"` supprimée sur `/votes/decryptes/environnement`.
- Communes à parenthèses accessibles (le legacy les liait mais les refusait en 400).
- Ajouts : pages `/commissions`, API Platform lecture seule sous `/api`, pages
  `/legislature-N`, listes historiques de groupes, sitemap inactifs étendu L14-16.
- Recherche : votes triés par récence + filtre `state='published'` (documenté dans
  `SearchController`, faute d'index FULLTEXT équivalent au MATCH legacy).
- Municipales 2026 et tables `elect_bv_*` : chantier en cours du legacy,
  délibérément hors périmètre (cf. CLAUDE.md).
- Emails en clair et absence d'obfuscation Cloudflare : infrastructure, pas
  l'application.

## Reste à vérifier (couverture incomplète de la campagne du 2026-08-08)

- [ ] Trois sections n'ont pas eu leur comparaison HTML fine (agents interrompus) :
  **sous-pages groupes** (`/membres`, `/statistiques`, `/votes`, groupe dissous
  ecolo L14, groupe NI), **fiche d'un vote décrypté** (texte du décryptage, votes
  similaires), **fiche député en HTML** (JSON-LD, liens sociaux). Elles ont été
  couvertes par la comparaison visuelle et des sondages ciblés (`/groupes`,
  vote_1 L16, vote_1243 L16 : conformes hors points déjà listés), mais une passe
  HTML complète reste à faire.
- [ ] Rejouer la comparaison des pages touchées après chaque correction ci-dessus
  (une URL à la fois côté datan.fr, cf. rappel 429 en tête de fichier).
