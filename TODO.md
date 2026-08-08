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

- [ ] **Commune « Faux » (Dordogne) importée sous le slug « 0 ».**
  `/elections/resultats/dordogne-24/ville_faux` → 404 ; la page existe sous
  `/…/ville_0` avec « Résultats des élections à 0 » en title/h1/breadcrumb, et le
  sitemap annonce `ville_0`. Cause quasi certaine : « faux » converti en booléen
  puis casté en chaîne quelque part (import des communes ou génération du slug).
  - Corriger la source (slug + nom en base), pas seulement la route.
  - Vérifier : `ville_faux` → 200 avec « Faux » partout ; `ville_0` → 404 ou 301 ;
    le sitemap `elections-v` n'annonce plus `ville_0` ; chercher d'autres victimes
    du même cast (`SELECT * FROM commune WHERE nom IN ('0','1','') OR slug IN ('0','1','')`).

- [ ] **Slugs de communes à article parenthésé : poser des 301.**
  `/elections/resultats/jura-39/ville_etoile` et
  `/elections/resultats/ardeche-07/ville_nonieres` répondent 200 sur le legacy
  (et sont liées depuis ses pages département) mais 404 sur le staging, qui ne
  connaît que `ville_etoile-(l)` et `ville_nonieres-(les)`. Une URL legacy ne rend
  jamais 404 : rediriger en 301 vers la forme du staging, sur le modèle de
  `DepartementController::versLAdresseCanonique()`.
  - Le legacy est incohérent avec lui-même (il garde « (les) » pour Assions mais le
    retire pour Nonières) : recenser TOUTES les communes dont le slug legacy diffère
    du slug staging (diff des sitemaps `elections-v` + `localites-v`) et couvrir la
    liste entière, pas seulement ces deux-là.
  - Nota : `ville_assions-(les)` et `ville_bonvillers-(mont)` étaient inaccessibles
    sur le legacy lui-même (400 CodeIgniter) : leur fonctionnement en staging est une
    correction assumée, rien à faire.

## P1 — Fiches vote : title et casse du h1 (18 311 pages, SEO)

- [ ] **Reprendre le gabarit `<title>` du legacy.**
  Legacy : `Vote n°997 - Vote final - <titre> - 17e législature | Datan` ;
  staging : `<titre brut> - Vote n°997 - Datan` (avec le titre en minuscule
  initiale). Reproduire le format legacy, y compris le segment « Vote final - »
  quand il y est et le suffixe « Ne législature | Datan ».
- [ ] **Retirer le `|capitalize` (ou équivalent) du h1.**
  Twig `capitalize` écrase les majuscules internes : « Motion de censure de la
  **nupes** contre le gouvernement d'**élisabeth borne** » (vote_1 L16),
  « l'amendement de **m. aviragnet** » (vote_100 L15). Le legacy rend le titre tel
  quel avec une majuscule initiale : utiliser un `ucfirst` qui ne touche pas au
  reste.
  - Vérifier sur : vote_1 L16 (NUPES, Élisabeth Borne), vote_100 L15 (M. Aviragnet),
    vote_997 L17 (déjà tout en minuscules : ne doit pas bouger), vote_1243 L16.

## P1 — Données de production en retard ou non portées

- [ ] **Rejouer `app:import:explications`** contre la vraie base : les 3 cartes
  « Dernières explications de vote » de l'accueil datent d'avant l'import
  (et le staging affiche 2× Maxime Laisney).
- [ ] **Rattraper les décryptages postérieurs au 2026-07-09** : il en manque 2
  (acétamipride 20 juil., aide à mourir 15 juil. 2026) — 238 vs 240. Visible sur
  l'accueil, `/votes`, `/votes/decryptes`, `/soutenir` et les carrousels.
  Contenu éditorial non régénérable : import de récupération, jamais en cron.
- [ ] **Décider et porter la table des maires.** « Le maire de X est Y. » manque sur
  toutes les pages commune, et la ligne « 🏛️ Maire » manque dans l'encadré des pages
  résultats. L'omission est documentée dans `templates/departement/commune.html.twig`
  (table vide dans la base de dev). Récupérer l'export TSV depuis la prod (docblock
  `docker exec` à poser sur la commande d'import) ou assumer l'absence — mais le
  legacy l'affiche, donc parité = la porter.
- [ ] **Porter la section « Ses professions de foi » des fiches député** (tableaux
  législatives 2024/2022, boutons « Profession 1er/2nd tour »). Section entière
  absente du staging (~460 px). Vérifier d'où le legacy tire les fichiers PDF/liens.
- [ ] **Déployer les images in-article du blog** : `/assets/imgs/posts/inside/*.png`
  → 404 sur le staging. Invisible aujourd'hui (le HTML stocké des articles pointe en
  absolu vers `https://datan.fr`) mais tout casse à la bascule de domaine.
  Copier le répertoire depuis la prod, puis vérifier chaque `posts/inside/` référencé
  par un article → 200.

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

- [ ] **Bug de gabarit des cartes d'élection** : valeur nulle rendue en chaîne vide
  avec bascule de libellé — Municipales : « 337 députés candidats » (legacy) →
  «  député élu » (staging) ; Présidentielle : « 0 député élu » → «  député élu ».
  Afficher le bon chiffre (les candidatures municipales ne sont pas importées :
  décider quoi afficher en attendant) et le bon libellé, ne jamais rendre vide.
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

## P2 — Typographie et casse transverses

- [ ] **Séparateur décimal** : le staging affiche la virgule (0,86 ; 4,4 %) là où le
  legacy affiche le point (0.86 ; 4.4 %) — systématique (statistiques, cohésion,
  évolutions de population). La virgule est le bon usage français mais s'écarte de la
  parité : trancher une fois pour tout le site et commenter. (Incohérence actuelle :
  « 51.7 ans » garde le point des deux côtés.)
- [ ] **« Polynésie Française » → « Polynésie française »** : la règle de
  capitalisation du staging surcorrige un adjectif ; le legacy avait raison ici.
  Régression à corriger dans le formateur de noms de départements.
- [ ] Capitalisations gardées (« Val-d'Oise », « Côtes-d'Armor », « 2A »/« 2B ») :
  corrections assumées du legacy — vérifier juste qu'un commentaire le dit.

## P2 — Divers pages et gabarits

- [ ] **Boutons de l'accueil** « Voir les derniers votes » / « Tous les votes » :
  staging → `/votes/legislature-17`, legacy → `/votes`. Aligner sur `/votes` (parité,
  et c'est l'URL du menu).
- [ ] **Meta description des pages d'archives mensuelles** : remettre la spécificité
  du mois (« en juin 2024 ») au lieu de la description générique de législature.
- [ ] **Section campagne de dons (`#campaign`)** : inclusion incohérente — absente de
  `/votes` côté staging (présente côté legacy), présente sur les pages mois côté
  staging (absente côté legacy), position différente sur les pages commune. Décider
  la liste des pages porteuses et l'emplacement, puis harmoniser.
- [ ] **Camembert « Résultat du vote »** : étiquette « 113 » affichée dans la part
  verte côté staging, aucune côté legacy. Retirer l'étiquette.
- [ ] **Bouton « Le dossier »** : icône de lien externe manquante côté staging.
- [ ] **Bulle d'aide « ? »** du titre « Les coalitions les plus fréquentes »
  manquante côté staging.
- [ ] **Ordre des logos** dans « La position des groupes » (fiche vote) : le tri
  legacy est un choix — le retrouver et le reproduire.
- [ ] **Badges des coalitions** triés alphabétiquement côté staging, ordre politique
  côté legacy : reproduire l'ordre legacy.
- [ ] **Tooltip hémicycle** : « Députés non inscrits (NI) » (legacy) vs
  « Non inscrit (NI) » (staging).
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
