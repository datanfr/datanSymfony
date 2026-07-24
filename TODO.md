# Reste à faire

État au 22 juillet 2026. Les conventions et les pièges métier sont dans
`CLAUDE.md` ; ce fichier ne liste que le travail restant.

Le périmètre de référence est `../datan/application/config/routes.php` : toute
adresse publique qu'il sert et que nous ne servons pas est un trou, sauf mention
contraire ci-dessous.

## 1. Défauts ouverts

À corriger avant d'ajouter des pages : ce sont des adresses qui répondent mal
aujourd'hui.

- [x] **Les députés corses sont en 404** — **corrigé le 23 juillet.** La
      parenthèse de `ImportMandatsCommand.php:101` fermait avant le code
      (`haute-corse-2B`) : le `strtolower()` enveloppe désormais la
      concaténation entière. Données réalignées par `UPDATE … LOWER(dpt_slug)`
      (strictement équivalent à un réimport, le slug étant dérivé) : 12 lignes.
      Vérifié par HTTP : les 12 fiches répondent 200 et figurent aux deux plans
      de députés — le filtre de `SitemapController`, écrit sur le motif de la
      route, s'est effacé de lui-même et reste en garde.
- [x] **995 députés n'ont pas de `dpt_slug`** — **404 depuis le 23 juillet.**
      L'enquête a tranché le « soit… soit » : ces 995 lignes n'ont **aucun
      mandat** (995 = 3 117 députés − 2 122 avec mandats) — ce sont des acteurs
      du dépôt Tricoteuses qui n'ont jamais siégé à l'Assemblée (sénateurs :
      Tasca, Antiste, Loueckhoté…). La production ne publie que les 2 119
      acteurs de sa table `deputes_last` : il n'y a **rien à reconstituer**,
      leur page n'a jamais existé. Garde `dpt_slug IS NULL → 404` posée dans
      `DeputeController::individual()` et `depute()` (donc `/votes` et
      `/legislature-N` aussi). Vérifié : fiche et sous-pages en 404.
      **Affinée le 23 juillet** après le balayage de l'agent des données : un
      slug de député n'est pas unique (le député Jean-Louis Masson, Var,
      partage le sien avec un sénateur homonyme sans page ; idem
      `beatrice-descamps`), et le `LIMIT 1` pouvait tirer l'homonyme et
      404-iser un vrai député que le plan annonçait. À slug égal, la ligne
      avec `dpt_slug` gagne (`ORDER BY (dpt_slug IS NULL)`), les deux fiches
      revérifiées en 200.
- [x] **`depute_legislature` n'a pas de garde de législature** — **corrigé le
      23 juillet.** Seuil `Legislature::PREMIERE` posé comme dans
      `VoteListController::liste()` ; `legislature-12/13` répondent 404,
      `legislature-14` toujours 200. Le bloc « Ses autres mandats » de
      `depute/legislature.html.twig` cite désormais les mandats pré-14e **sans
      les lier** (il fabriquait des liens morts vers ce que la garde ferme).

- [ ] **Les fiches des Français de l'étranger vivent à la mauvaise adresse.**
      Découvert le 25 juillet en portant les redirections du `.htaccess` : la
      redirection canonique de `DeputeController::individual()` (et le plan de
      députés, et tout lien interne) suit `depute.dpt_slug` — le slug
      **fabriqué** (nom + code), qui pour les Français de l'étranger donne
      `francais-etablis-hors-de-france-099` quand datan.fr sert
      `francais-de-letranger` (la cible Yadan du `.htaccess` le prouve). Le
      piège des « deux jeux de slugs » de CLAUDE.md : cinq départements
      divergent, leurs députés sont donc indexés chez nous à des adresses que
      le site vivant n'a jamais servies. À corriger en dérivant `dpt_slug` de
      `departement.slug` (la table du legacy) quand le code y figure — puis
      revérifier fiches, plans et sitemap. Confié à l'agent de la fiche député.

## 2. Référencement

**Chantier livré le 23 juillet.** Le mécanisme vit dans `base.html.twig` (qui
fabrique toutes les balises depuis des valeurs sûres) + `App\Referencement\OpenGraph`
(les cartes composées) + `partials/fil_ariane.html.twig`. Ce que doit savoir
quiconque ajoute une page :

- **le bloc `meta_description` ne contient plus que du TEXTE** — la balise est
  fabriquée par `base.html.twig`, qui ressert le même texte à `og:description`
  et `twitter:description` (les 42 gabarits ont été refondus en ce sens) ;
- **`fil_ariane`** : passer du contrôleur une liste de `{nom, url}` — dernier
  maillon actif par défaut, `actif: false` pour le forcer en lien (cas de
  l'article de blog). Rendu **en bas de page**, comme l'origine, + JSON-LD
  `BreadcrumbList` dans le `<head>` ;
- **`ogp`** : uniquement pour les pages à visuel dédié (député, groupe, scrutin,
  article) — sans lui, carte générique au logo 1200×630, comme l'origine.

Détail des quatre points, tous vérifiés côte à côte avec datan.fr :

- [x] **`canonical`** sur toute page, auto-référente comme l'origine mais **sans
      la chaîne de requête** (le legacy recopie REQUEST_URI entier, `?page=2`
      compris — défaut corrigé, commenté dans le gabarit).
- [x] **Open Graph + Twitter Cards** : les quatorze balises sur toutes les
      pages ; cartes composées par `og-image-datan.vercel.app` (le générateur de
      la production, toujours en service) pour député (fiche + historique),
      groupe, scrutin décrypté et explication mise en avant — adresses
      identiques au vivant à l'octet près (espaces seuls encodés `%20`, accents
      bruts : ne pas « corriger » en `rawurlencode`). `profile:first_name/last_name`
      sur les fiches. Le titre d'un vote final non décrypté suit la règle de
      l'origine (« … - Vote final » sur le dossier).
- [x] **Fil d'Ariane partout** où l'origine en rend un — donc ni sur l'accueil
      ni sur les commissions (vérifié en vivant). Noms repris à l'identique, y
      compris les incohérences voulues (« 16ème législature » côté députés,
      « 16e » côté votes) ; deux coquilles corrigées et commentées : `ListItem`
      (le legacy émet « listItem », que schema.org ignore) et l'accent
      d'« Historique 16e législature ». **Les pages `/elections` ont désormais
      le leur** (24 juillet), câblé sur ce mécanisme d'après les fils de
      `Elections.php` : « Datan › Élections », suivi selon la page du scrutin
      (« Législatives 2022 »), du département (« Rhône (69) ») ou de la commune.
      Paris n'a pas de maillon de département sur sa fiche de ville d'élection —
      sa page de département rend 404 —, comme le legacy. `/parrainages-2022` en
      rend un aussi (« Datan › Parrainages 2022 ») ; son URL de JSON-LD pointe la
      vraie adresse, là où le legacy écrit `/parrainages` (404). Le simulateur de
      coalition n'en a pas, le contrôleur d'origine n'en produisant pas. Aucune de
      ces pages n'a de carte Open Graph dédiée (logo générique, comme le legacy).
- [x] **Redirections 301 du `.htaccess`** — **portées le 25 juillet**
      (`RedirectionLegacyController`). Le legacy ne tient pas tout son contrat
      d'URL dans `routes.php` : `.htaccess.dist:24-60` porte les adresses
      d'avant la mise en législatures (2022), indexées depuis des années —
      `votes/vote_N` et `votes/all…` → `legislature-15`, les 17 adresses
      courtes de groupes (`/groupes/soc`…, énumération en contrainte de route
      pour ne pas avaler `legislature-N`), trois députés déménagés (Sas,
      Lucas-Lundy, Yadan — `priority: 2`, sinon `depute_individual` les avale),
      les votes filtrés (`…/votes/{champ}` → `/votes`, lookahead qui épargne
      le vrai `/votes/all` des groupes), `dashboard-mp` → `dashboard` (le
      motif du pare-feu resserré en `^/dashboard(/|$)`, sans quoi il capturait
      l'ancienne adresse avant le 301). Slash final : Symfony le retire
      nativement en 301 ; doubles slashes (hook `urlValidator`) : aucune route
      ne les accepte, 404 naturel. Tout vérifié en prod, y compris les
      non-captures (vraies pages en 200).
- [x] **Page 404** : `templates/bundles/TwigBundle/Exception/error404.html.twig`.
      Attention, la description qui figurait ici était fausse — le
      `404_override` « errors/page_missing » du legacy est **commenté** dans
      routes.php ; ce que datan.fr sert réellement (vérifié en vivant) est la
      page turquoise autonome de `views/errors/html/error_404.php`, sans
      en-tête ni pied de page. C'est elle qui est portée, et elle sert en prod
      (cache reconstruit et vérifié par HTTP).
- [x] **Obfuscation du maillage interne des communes** (23 juillet). Le site
      masque aux robots les adresses des petites communes — préfixe leurre +
      ROT13 décodé au clic (`url_obf2.js`, chargé par `base.html.twig` et
      interdit aux moteurs par `robots.txt`). Reproduit via la fonction Twig
      `url_obf()` : liste alphabétique d'un département d'élections (seuil
      500 hab ; les quinze pastilles, déjà en clair, ne sont pas doublées),
      « Voir la page commune » (seuil 4 000), communes voisines (toujours,
      sur les deux fiches de ville), Paris au pied des pages d'élections
      (déjà en clair parmi les grandes communes). Les liens **externes**
      restent en clair avec `rel="nofollow"` — divergence assumée, commentée
      en tête de `classement/deputes-origine-sociale.html.twig`. Notre
      décodeur corrige au passage l'accessibilité (focus + clavier) et cible
      l'attribut `url_obf`, pas la classe, qui habille aussi de vrais liens.
- [x] **Le pied de page est obfusqué hors accueil** — **tranché et porté le
      24 juillet**, en parité (c'est un choix de sculpture du crawl, pas un
      défaut) : À propos, Newsletter, Connexion, Mentions légales, quatre
      réseaux sociaux, data.gouv et GitHub deviennent des `span url_obf` hors
      de `/`, le reste demeure en clair partout (footer.php:71-207, vérifié en
      vivant sur datan.fr/faq). Le pied de page a été remis en parité complète
      au passage : cinq réseaux et non deux (Bluesky reste un vrai lien dans
      les deux branches — laissé tel quel au legacy après son ajout, reproduit),
      bandeau data.gouv/GitHub, ligne de copyright, bloc « Nous contacter »,
      images en chargement paresseux ; le lien « Commissions », que le legacy
      n'a pas, est retiré. Le PNG Facebook (au lieu du SVG) de la branche
      masquée est une coquille du site, reproduite et commentée.

## 3. Pages publiques non portées

Éditorial simple — même moule que `/statistiques/aide`, aucune donnée :

- [x] `/a-propos`, `/mentions-legales`, `/soutenir` — **portées** (22 juillet).
      **Cette liste est close** : la route fourre-tout `(:any) -> pages/view/$1`
      du legacy ne sert pas une table administrable mais des fichiers de vue, et
      il n'y en a que quatre — le quatrième, `statistiques.php`, est déjà porté
      en `/statistiques/aide`. Rien ne se cache derrière ce fourre-tout. Le
      contrôleur legacy prépare aussi un titre pour `contact`, mais le fichier de
      vue n'existe pas : l'adresse répond 404 sur datan.fr, il n'y a rien à
      porter.
      Les cinq liens du menu et du pied de page qui menaient à ces pages
      (« Nous soutenir », « À propos » ×2, « Dons », « Mentions légales ») sont
      désormais câblés dans `base.html.twig` (23 juillet). Un point reste en
      attente d'un autre chantier :
      - **Le texte des mentions légales décrit des cookies que nous ne posons
        pas** : Tarte au citron, Google Analytics, Matomo. Il est repris tel quel
        — c'est un document juridique, pas une page à réécrire —, mais il devra
        être confronté à la réalité du déploiement avant mise en ligne. Le site
        pose aussi `pg-mentions` sur `<body>`, dont l'unique effet est de faire
        apparaître l'icône de Tarte au citron : sans le gestionnaire, la classe
        n'a rien à montrer.
- [x] `/faq` (`Faq.php`) — **portée** (23 juillet). Deux entités (`FaqCategorie`,
      `FaqPost`), une migration, `app:import:faq` sur le socle, et le gabarit
      `faq/index.html.twig` : bandeau vert, recherche client (`#searchfaq`, déjà
      câblée dans `main.js`), accordéon Bootstrap par catégorie. **6 catégories et
      11 questions récupérées, 10 publiées** — le brouillon (id 3) est conservé
      comme donnée mais masqué de la page publique. Faute de colonne de tri dans la
      source, l'ordre d'origine est repris de l'identifiant (`ordre`) ; la page ne
      montre que les 3 catégories ayant au moins une question publiée (2, 3, 4). Le
      jeton `[[ageMean]]` d'une réponse est résolu **au rendu** par l'âge moyen des
      députés en exercice (52 ans), comme le legacy, et non figé à l'import.
      **Reste** : le JSON-LD `FAQPage` (`Faq_model::get_faq_schema()`), laissé au
      chantier des balises structurées. Le lien « Foire aux questions » du pied de
      page (§5) peut désormais viser `/faq`.

Avec données :

- [x] `/elections`, `/elections/{slug}`, `/elections/resultats/{dpt}`,
      `/elections/resultats/{dpt}/ville_{commune}` — **portées et vérifiées**
      (23 juillet). `ElectionController`, gabarits `templates/election/**`, les
      deux blocs de résultats de la fiche de ville (`templates/commune/*`) et les
      trois sitemaps d'élections. Vérifié sur un serveur prod unique, comparé à
      datan.fr et à la base : les quatre adresses en 200
      (`/elections/resultats/paris-75` en 404 comme le legacy), cache
      `s-maxage=3600`, HIT ~25 ms ; Villeurbanne reproduit au chiffre près
      (Amard 25 352 / 92 020 / 53 827).
      **Défauts corrigés** (parité rétablie face à datan.fr) :
      - **Corse non traduite sur `/elections/legislatives-2022`.** `candidature.district`
        écrit « 2a »/« 2b » mais `departement.code` « 2A »/« 2B » : le lookup PHP,
        sensible à la casse là où une jointure ne l'est pas, faisait tomber les
        candidats corses sur le repli « nom de département ». Lookup en minuscules,
        casse canonique rendue partout (« Haute-Corse (2B) ») — le legacy garde ses
        minuscules, écart assumé.
      - **Ordre des cartes sur `/elections`.** Le site classe année décroissante
        puis identifiant croissant (européenne avant législative de 2024) ; nous
        triions par date. Corrigé dans `ElectionRepository::toutes`.
      - **Double arrondi des % législatifs.** `legislativesParCommune` pré-arrondissait
        à deux décimales avant le `|round` des gabarits : Braun-Pivet (49,4977 %)
        sortait à 50 au lieu de 49. Part rendue brute, arrondie une seule fois.
      - **Filtre « par groupe » de legislatives-2022**, vide en production (défaut
        du legacy), désormais renseigné (12 groupes, options ↔ classes `gp-*`).
      **Écarts assumés** (choix, non défauts) :
      - **`/elections/resultats/{dpt}/ville_{commune}`.** Le legacy la sert creuse
        (`elect_bv_*` vides, cadrage municipales 2026 hors périmètre) ; nous y
        servons les résultats législatifs par circonscription depuis
        `resultat_legislative`, plutôt qu'une page vide (choix confirmé, documenté).
      - **24 communes à slug parenthésé** (`ollieres-sur-eyrieux-(les)`…). datan.fr
        renvoie 400 (parenthèses rejetées en amont) ; nous **les servons** en 200
        avec leurs données (choix confirmé), mais **hors sitemaps** — les 5 de plus
        de 500 hab retirées de `sitemap-elections-v` (16 553 URLs). `SLUG_COMMUNE`
        reste ouvert aux parenthèses.
      - **Pied de `/elections`.** Le legacy regroupe les communes vedettes par nom
        et classe le groupe sur une population indéterminée, ce qui sort Saint-Denis
        (Réunion) des trente ; nous classons chacune sur sa propre population, et
        Saint-Denis reprend son 20e rang. La nôtre est la plus juste.
      - **Coquilles corrigées et commentées** : espace devant « % » dans les barres
        de la présidentielle 2022 (le site écrit « 41% »), accord « arrivée » et mot
        « départemental » aux départementales 2021.
- [x] **Résultats de la circonscription sur la fiche d'un député** — **portés**
      (23 juillet). Trois entités (`ResultatCirconscription`,
      `ParticipationCirconscription`, `PartielleLegislative`), une migration,
      `app:import:circonscriptions` sur le socle, et le bloc « Son élection »
      (`depute/_election.html.twig`). **Comptes : 2 227 participations, 7 442
      résultats, 361 partielles — toutes reprises, 0 écartée.** Deux pièges de la
      source réparés à l'import : le **double encodage UTF-8** des noms
      (« Ã‰ric » stocké pour « Éric » — réparé en relisant en Windows-1252), et
      les **deux façons de nommer** — nom complet dans `candidat` jusqu'en 2022,
      éclaté en `nameLast` (capitales) / `nameFirst` en 2024, le nom remis en casse
      de titre (« SAINTE-MARIE » → « Sainte-Marie », là où le legacy le mange en
      « Sainte-marie »). La règle de substitution partielle → élection générale, le
      regroupement « Autres candidats » au 1er tour et la participation (comparée à
      la moyenne nationale codée en dur) vivent dans
      `ResultatCirconscriptionRepository`. Coquille legacy « supérieux » corrigée en
      « supérieur », et le `</^p>` malformé de la vue refermé. Le nom du député lève
      l'ambiguïté quand la circonscription a connu une partielle ; un suppléant qui
      a pris le relais sans partielle n'a pas de bloc, comme sur datan.fr.
- [x] `/blog`, `/blog/{categorie}/{slug}`, `/blog/categorie/{slug}` — **portées
      le 23 juillet** (`BlogController`, gabarits `blog/`), avec les deux
      derniers sitemaps (`sitemap-posts-1.xml`, `sitemap-categories-1.xml` — les
      quatorze plans sont désormais tous servis, chaque adresse vérifiée en 200).
      Trois choses apprises en route :
      - **Les sous-titres et descriptions des rubriques ne sont pas en base** :
        le legacy les code en dur dans `libraries/Blog.php` (sa table
        `categories` n'a que nom et slug). Repris tels quels en constante de
        `BlogController`.
      - **Les images des articles étaient absentes de nos assets.** La base
        référence des noms suffixés d'un horodatage d'upload
        (`img_post_6_1754771726`) qui n'existent ni dans notre copie ni dans le
        dépôt legacy — la production les fabrique à l'upload. Les 18 jeux
        (base + variantes -360/-420/-730/-1240 + WebP, 162 fichiers) ont été
        récupérés depuis `datan.fr/assets/imgs/posts/`.
      - Le fil d'Ariane d'un article lie ses **quatre** maillons, l'article
        compris — seule page du site sans maillon actif ; c'est le comportement
        de l'origine, reproduit via `actif: false`.
- [x] `/parrainages-2022` (`Parrainages.php`) — **portée le 24 juillet.**
      `ParrainageController` + `parrainages/index.html.twig`, entité `Parrainage`,
      migration, `app:import:parrainages`. Les 13 427 parrainages 2022 récupérés
      (0 écarté) ; la page n'en affiche nommément que le volet des **530 députés**,
      le décompte « plus de 500 signatures » se faisant sur l'ensemble. Trois
      colonnes viennent de la fiche du député (jointure `depute`), non de la table
      `parrainages` : le lien est **obfusqué** (`url_obf`) vers `depute_individual`
      et **conditionné à l'existence d'une fiche** (`dpt_slug`+`slug`) pour ne
      jamais fabriquer un 404 — les 530 en ont une, la garde tient pour les autres ;
      le **groupe** par le rattachement le plus récent (`fonction_groupe`), non par
      `depute.groupe_id` qui laisserait 377 anciens députés sans groupe là où
      datan.fr montre leur dernier connu ; le **département** en casse canonique
      (« Haute-Corse (2B) »), même correction assumée que les pages d'élections.
      **Le nom, lui, reste celui de la source** (`parrainages.nom/prenom`) : accents
      parfois absents (« Eric »), instantané de 2022 (« Nicole Gries-trisse ») —
      c'est ce qu'affiche datan.fr, jusqu'à lier ce nom-là à la fiche au nom actuel.
      Table `datatable-datan.min` et graphique `chart.min` repris du legacy. Cache
      1 h, `og:image` générique. Fil d'Ariane en §2.
- [x] `/questionnaire` (`Quiz.php`) — **portée.** `QuizController` +
      `quiz/index.html.twig`. **Ne consomme PAS `question_quiz`** (récupérée par
      `app:import:quiz`) : cette table n'alimente que
      `Quizz_model::get_questions_api()`, branchée sur aucune route de
      `routes.php` — un service pour une application tierce, sans page. Ce que
      `/questionnaire` affiche, comme le legacy, ce sont les **trois derniers
      votes décryptés** (`get_most_famous_votes(3)`), proposés en « pour / contre
      / abstention » pondéré. Notre page rend le PLFSS 2026 (les trois plus récents
      décryptages, confirmés identiques dans la base de production) ; datan.fr en
      montre trois plus anciens, sa page étant en cache HTTP. Coquille corrigée :
      le `<title>` du legacy dit « Blog | Datan » (copier-coller du blog), rétabli
      en « Quel député choisir ? ». Le calcul de proximité (`Quiz::result()`) est
      resté inachevé côté legacy (vue rechargée sans votes, affichage du score
      commenté) : `/questionnaire/resultat` redonne le questionnaire au lieu de
      rendre une page morte. Cache 1 h.
- [x] `/outils/coalition-simulateur` — **portée le 24 juillet.**
      `OutilsController` + `outils/coalition.html.twig`. `coalition_builder.js`
      (intouché) lit un global `groups` posé en ligne, indexé par sigle, chaque
      entrée portant `seats` et `color` ; on lui sert exactement cette forme.
      **Effectifs comptés sur `fonction_groupe.nomin_principale`, jamais sur
      `depute.groupe_id`** — 577 sièges principaux ouverts, l'hémicycle SVG en a
      autant de cercles. Couleurs via `CouleurGroupe`, ordre par effectif décroissant,
      NI compris. Les douze sigles et leurs couleurs collent au vivant ; deux
      effectifs diffèrent — **EPR 91 / HOR 35 chez nous, 90 / 36 sur datan.fr**. Ce
      n'est pas un défaut : notre chiffre est celui de la table `groupes_effectif`
      de la base de production (91 / 35) et de la méthode prescrite ; le site sert
      un instantané plus ancien. FAQ structurée (`FAQPage`) reproduite. Pas de fil
      d'Ariane (le contrôleur d'origine n'en rend pas), `og:image` générique,
      cache 1 h. Encart de dons inclus (`partials/campagne.html.twig`, masqué tant
      qu'aucune campagne n'est active). `App\BlocPolitique` n'est pas utilisé : le
      simulateur liste des groupes réels, pas une lecture en blocs.
- [x] `/recherche/{q}` et `/search_api` — **portés.** `SearchController` (+ macro
      `search/_icones.html.twig`). Six familles dans l'ordre de l'UNION d'origine
      (députés, groupes, villes, départements, votes décryptés, articles), rendues
      en six requêtes distinctes plutôt qu'une UNION : même résultat, et le tri par
      famille (villes par longueur puis population, départements par nom) reste
      honoré, ce qu'un `ORDER BY` de membre d'UNION n'est sous MariaDB qu'avec un
      `LIMIT`. `/search_api` rend le JSON `{text, url, source}` **à l'octet près**
      de datan.fr (vérifié sur `lyon&type=ville` : `\/`, `ê`, chevrons bruts) —
      ce qui fait marcher l'autocomplétion (`dist/autocomplete_search.js`) sans
      toucher au JS. Pas de normalisation PHP des accents : la collation retrouve
      « Rhône » depuis « rhone », et `highlight_phrase` reste littéral (accents non
      repliés, sans drapeau `u`), comme le legacy — l'accent se voit donc dans la
      recherche mais pas dans le gras, parité exacte. Trois défauts corrigés : le
      brouillon d'un vote décrypté n'est plus exposé (le legacy ne filtrait pas
      l'état) ; l'adresse d'un vote du Congrès prend la forme `vote_cN`, sinon lien
      cassé ; l'article pointe sa vraie rubrique et non le « rapports » codé en dur
      (notre route valide la rubrique). Le `MATCH` plein texte, sans index
      FULLTEXT chez nous, devient un `LIKE`, trié par récence. Page noindex, cache
      1 h ; API non mise en cache partagé, comme le legacy. **À câbler côté
      `base.html.twig` / accueil (chantier de Rémi)** : la barre de recherche
      (`id="search"` + `search-bloc` / `-results-bloc` / `-results-list` /
      `more-results-link`, markup dans `home/index.php` du legacy) et le chargement
      de `autocomplete_search.js` ne sont pas encore dans les gabarits ; le champ
      `citySearch` des pages d'élections existe déjà et fonctionne désormais.
- [x] `/iframe` et `/iframe/depute/{slug}` — **portés.** `IframeController` +
      `iframe/depute.html.twig` (document autonome, sans `base.html.twig`) +
      `iframe/index.html.twig`. **Embarquable** : aucun `X-Frame-Options` ni CSP
      `frame-ancestors` (l'application n'en pose aucun, le contrôleur non plus —
      vérifié `curl -I`), cache public 3 jours comme le legacy
      (`output->cache("4320")`). Reprend la parade de slug non unique de
      `DeputeController` (jamais modifié ici) : `ORDER BY (dpt_slug IS NULL), id`,
      `dpt_slug NULL → 404`. Paramètres `?categories=`, `?first-person=true`,
      `?main-title=hide`, `?secondary-title=hide` honorés. Contenu : les blocs de
      la fiche tels que ce portage les rend (positions importantes, derniers votes,
      élection, participation/loyauté), la participation locale du bloc élection
      masquée en contexte iframe comme le legacy. Ni pistage (Matomo, GTM) ni
      bannière de consentement (tarteaucitron) — les injecter chez un tiers serait
      un défaut, corrigé. **Écart assumé** : datan.fr sert dans l'iframe la version
      riche de la fiche (carrousels de votes, graphiques du comportement politique)
      que notre fiche `/deputes` n'a pas encore ; l'iframe est donc au niveau de la
      fiche, pas à celui du legacy. Blocs `explication` et `questions` non portés
      (le second est déjà désactivé côté legacy).
- [x] `/votes/legislature-{n}/vote_{n}/explication_{mpId}` — **porté le
      22 juillet**, dans ses deux formes, `vote_{n}` et `vote_c{n}`. C'est le
      lien que le tableau de bord donne à partager : la page du scrutin, l'auteur
      mis en avant. Le segment est le `mp_id` (`PA841451`), pas le slug. Une
      explication retirée ou repassée en brouillon renvoie en 302 vers la page
      ordinaire, comme le legacy — l'adresse a pu être partagée, le scrutin, lui,
      existe toujours.
- [x] **`/classements` redirige vers `/statistiques`** — **fait le 23 juillet**
      (`ClassementController::ancienneAdresse()`). En **301**, quand datan.fr
      répond 307 (le `redirect()` de CodeIgniter sans code) : un déplacement
      définitif d'adresse indexée se dit en 301, défaut corrigé. Quant à
      `/redirect/cities/{code}/{dpt}` : **vérifié le 23 juillet, il répond 404
      sur datan.fr même** (deux codes INSEE valides essayés) — l'adresse est
      morte à la source, il n'y a rien à porter.

Abandonné sciemment, ne pas y revenir :

- **Le maire d'une commune.** `cities_mayors` est vide dans la copie dont nous
  disposons, et ce que la production affiche a dérivé (« Le maire de Amiens est
  Hubert De jenlis »). Une donnée fausse ne vaut pas mieux qu'une absente.

## 4. Espaces authentifiés

Rien n'est porté au-delà de `/connexion` et de `/admin/decryptages`.

- [x] **Back-office — campagnes, FAQ, questionnaire, parrainages, tableaux
      d'analyse** (`Admin.php`, `routes.php:53-88`). Portés le 24 juillet, sous
      la coque `admin/base.html.twig` (menu de rédaction ajouté), formulaires
      Symfony + CSRF, aucune entité exposée en `#[ApiResource]`, pas de cache
      HTTP. Règles de rôle reprises du legacy : tout rédacteur crée, reprend un
      brouillon et publie ; **seul l'administrateur reprend un contenu publié**
      (FAQ, questionnaire — `modify_*` renvoie à la liste si `state==published &&
      usernameType!=admin`) **et supprime** (`delete_*`, `delete_campaign`).
      Vérifié aux deux comptes : anonyme 302, rédacteur 200 partout / 302 sur une
      modif publiée / 403 sur une suppression, administrateur 200. Le CRUD des
      campagnes rend l'encart de dons pilotable (activation → `/campaign/current_active_campaigns`).
  - **Écarts assumés, à faire quand la donnée existera :**
    - *Postes Assemblée* (mandats en organe / commissions) : notre schéma ne
      porte pas de mandats secondaires ni de table `organe` — l'écran l'affiche
      franchement au lieu d'un tableau vide.
    - *Comptes X des députés* : les réseaux sociaux sont une donnée que Datan
      tient à la main, pas encore importée (cf. plus haut) — même traitement.
  - **Laissé de côté sciemment :** `admin/votes` (c'est le CRUD des décryptages,
    déjà sous `/admin/decryptages`) ; `admin/api-keys` — **tranché le 24 juillet :
    caduc.** Le seul client de l'API à clés du legacy était PoliticAnalysis, le
    service externe qui générait les décryptages IA et les résumés d'amendements ;
    cette génération est désormais interne (cf. le brouillon par IA ci-dessous),
    il n'y a plus de client à authentifier ; `admin/elections/*` (fenêtre de
    candidatures close depuis 2022).
- [x] **Relecture des amendements et CRUD du blog** (`Admin::amendements`,
      contrôleur `Posts`) — **portés le 24 juillet.**
  - **`/admin/amendements`** (`AmendementController`) : la file des votes sur
    amendement de la législature en cours **non encore décryptés**, où la
    rédaction relit le titre et le résumé IA puis coche « relu ». Filtres
    période / dates / « masquer les relus » repris du legacy ; disparité et
    intérêt **calculés en SQL** à l'identique (`daily.php`). La case « relu »
    bascule `amendement.resume_relu` par un **POST + jeton CSRF** (l'AJAX du
    legacy `admin/amendements/review`), écriture par l'ORM. Le bouton
    « Décrypter », qui ouvrait le service externe PoliticAnalysis
    (`{PA_URL}/scrutins/{uid}/decryptage`), pointe désormais l'écran interne
    `/admin/decryptages/nouveau?legislature=&numero=`. Accès rédacteur **et**
    administrateur (le `security_only_team` du legacy), sans garde plus fine.
    - **Écart de schéma assumé :** le socle IA du legacy vivait dans une table
      `amendements_ia` clée sur le vote (`legislature`, `voteNumero`) ; chez nous
      résumé et drapeau vivent sur l'amendement (`resume_ia`, `titre_ia`,
      `resume_relu`), qu'un scrutin désigne par `amendement_id`.
    - **Note de simplicité (`simplicite_ia`) absente de notre schéma** : la
      colonne « Simplicité » est affichée **vide (« — »), pas inventée** — à
      ajouter le jour où la génération IA la produit (le legacy la rend en étoiles
      1–5).
  - **`/admin/blog`** (`Admin\BlogController`, distinct du `BlogController`
    public) : liste (brouillons compris), création, modification, suppression.
    Corps en HTML éditorial (CKEditor `#editor`, rendu `|raw`, comme la FAQ).
    Règles de rôle **alignées sur les décryptages** : création en brouillon,
    reprise d'un contenu publié et suppression réservées à l'administrateur — plus
    strict que le legacy (qui laissait un rédacteur rouvrir un publié sans pouvoir
    en changer l'état, ce qui le remettait à NULL, un défaut), mais uniforme avec
    le reste du back-office.
  - **`admin/exposes` : analysé, non porté (remplacé de fait).** L'écran du legacy
    n'éditait qu'une colonne, `exposes.exposeSummaryPublished` — la version relue
    de l'exposé des motifs, à côté de l'original (`exposeOriginal`) et d'un résumé
    OpenAI (`exposeSummary`). Or dès la 17e législature le legacy ne lit plus cette
    table : il **reconstruit** le texte depuis `amendements_ia`
    (`resume_ia` + `justification_ia`). Chez nous il n'y a pas de table `exposes`,
    et le résumé éditorial d'un amendement vit sur `amendement.resume_ia`, relu via
    l'écran des amendements ci-dessus — l'exposé publié y est donc déjà couvert.
    Rien à porter tant qu'on ne réintroduit pas la distinction original / résumé
    OpenAI / version publiée (et `justification_ia` n'existe pas encore chez nous).
- [x] **Brouillon de décryptage par IA** (`/admin/decryptages`) — **livré le
      24 juillet.** Refait en interne à partir de PoliticAnalysis et d'alinea
      (dépôts de référence clonés dans `../PoliticAnalysis` et `../alinea`),
      pour ne plus dépendre d'un service externe. L'essentiel : le brouillon
      s'appuie sur **les morceaux de discours tenus en séance** autour du vote.
      - Corpus des débats : dépôt Tricoteuses `Comptes_Rendus_Seances_XVII_nettoye`
        (`comptes-rendus` au Catalogue), importé par `app:import:comptes-rendus`
        (601 séances, 30 905 sections, 263 808 paroles ; 10e étape du sync
        quotidien). Tables `compte_rendu`, `cr_section`, `cr_parole` ; liens de
        hiérarchie par `ordre_absolu_seance`, pas par id (insertion par lots).
        Le lien vote → débat passe par `scrutin.seance_ref` (8 434/8 434 en
        L17, 18 séances sans CR : publication différée de quelques jours).
      - Services `App\Ia` : `CollecteurDecryptage` (ancrage de la discussion par
        la parole « Voici le résultat du scrutin » matchée sur les chiffres,
        section sœur précédente incluse — la discussion générale des séances
        antérieures est un choix de périmètre non couvert) ; `GenerateurBrouillon`
        (deux moteurs par `IA_MODELE` : `claude-*` → API Anthropic, SDK officiel,
        sinon Ollama local `OLLAMA_URL` ; sortie contrainte par schéma JSON) ;
        `ValidateurCitations` (chaque citation « … » vérifiée contre le compte
        rendu : exacte / bigramme / recouvrement 60 %).
      - Bouton « Générer un brouillon » dans le formulaire (visible seulement si
        `IA_MODELE` est posé) : pré-remplit titre et CKEditor, affiche le rapport
        de citations. **Jamais de publication automatique** — le décryptage est
        la seule donnée que Datan produit, l'IA propose, la rédaction dispose.
      - Vérifié bout en bout le 24 juillet (gemma4 local, scrutin 8372) :
        endpoint 200 en ~20 s, 403 sans jeton, 302 anonyme, bouton absent sans
        modèle ; 7 citations sur 8 retrouvées mot pour mot, la 8e signalée à la
        relecture — c'est le rôle du validateur.
      - **Résumés d'amendements branchés le 24 juillet** sur le même moteur
        (`App\Ia\MoteurIa`, extrait pour être partagé) : commande
        `app:ia:resumes-amendements` (titre_ia, resume_ia, simplicite_ia —
        colonne ajoutée, migration `Version20260724180000`). Ne touche jamais
        un résumé existant, `resume_relu` reste à la rédaction, **hors du sync
        quotidien** : chaque exécution appelle un modèle, on la lance sciemment.
        L'ancienne boucle PoliticAnalysis (`/api/amendements_ia`) n'a plus lieu
        d'être. `/admin/decryptages/nouveau?legislature=&numero=` pré-remplit le
        formulaire — la cible du bouton « Décrypter » de l'écran amendements.
        Vérifié en réel (gemma4, 2 résumés générés, relu=0). Piège consigné
        dans `MoteurIa` : les modèles Ollama à réflexion consomment leur budget
        `num_predict` en raisonnement AVANT la réponse — sans marge, contenu
        vide (`done_reason: length`).
      - **Reste :** choisir le modèle de production (`IA_MODELE` dans
        `.env.local` : `gemma4:latest` testé, `claude-haiku-4-5` dès qu'une
        `ANTHROPIC_API_KEY` est posée).
- [x] **Espace député** (`/dashboard`) — **porté le 22 juillet.** Un député
      connecté y rédige, publie, reprend et supprime ses explications de vote,
      aux adresses du legacy (`explications/create/l{n}v{n}` et ses jumelles).
      Un compte devient celui d'un député par son rattachement à une ligne
      `depute` (`app:utilisateur:creer --depute=<slug>`), et c'est ce lien seul
      qui porte `ROLE_DEPUTE` : rédaction et députés sont exclusifs, vérifié
      dans les deux sens.
      - [x] **`dashboard/elections/{slug}` et `/modifier`** — **portées le
        24 juillet** (`DashboardController`, gabarits `dashboard/elections/**`).
        La fiche de candidature (`DashboardMP::elections`) se lit dans `election`
        + `candidature`, déjà en base ; elle n'ouvre que pour les législatives
        2022 (`ELECTIONS_FICHE = [4]`, comme le `in_array($id, array(4))` du
        legacy). La **garde de fenêtre** est reproduite : `/modifier` renvoie 404
        dès qu'on est à deux jours ou plus du premier tour (`elections_modify →
        show_404()`) — close depuis des années pour 2022, la fiche se visite, la
        candidature ne se modifie plus. Le district (code de département) est
        traduit en nom comme `get_district`, la Corse « 2a » retrouvée par la
        collation. Coquille corrigée : « vendredi précédant » (participe) et non
        « précédent ». Ajout : une entrée **« Ma candidature »** dans la barre
        latérale du dashboard, le legacy ne menant à cette page par aucun lien
        (URL orpheline — ni sidebar, ni carte d'accueil).
      Reste de côté, sciemment : **le générateur d'iframe**, que le legacy ouvre
      sur un `show_404()` (« Temporary disallow iframe for MPs ») — il n'y a rien
      à imiter.
      Le tableau de bord reprend depuis le 23 juillet la **barre latérale
      sombre** du legacy (AdminLTE `sidebar-mini`, photo du député en médaillon,
      menu propre au rôle), et non plus la barre supérieure simplifiée. La coque
      `admin/base.html.twig` est partagée avec la rédaction, comme le
      `dashboard/header.php` d'origine qui bascule sur `$type`.
- [x] **Comptes députés : `/demande-compte-depute`** — **porté le 24 juillet.**
      `DemandeCompteController` (formulaire public, jeton CSRF, aucun cache HTTP)
      + `Admin\DemandeCompteController` (écran de traitement, `ROLE_ADMIN`) +
      entité `DemandeCompteDepute`. Le legacy (`Users::demande_mp`) vérifie que
      l'adresse est celle d'un député (`deputes_contacts.mailAn`, ici
      `depute.mail_an`, 2 057 renseignées) et qu'aucun compte n'y est rattaché,
      crée un jeton de 24 h et **envoie un courriel d'activation** — la
      possession de l'adresse `@assemblee-nationale.fr` valant preuve, et
      `/register/{token}` laissant le député créer lui-même son compte. Ce
      portage **n'envoie pas de courriel** (Mailjet, comme la newsletter, relève
      du déploiement) et `/register` appartient aux comptes lecteurs : la demande
      devient une **ligne en attente** qu'un administrateur relit et approuve —
      c'est lui qui transmet les identifiants à l'adresse institutionnelle, le
      contrôle par l'adresse s'y reporte plutôt que de se perdre. **Depuis le
      portage des comptes lecteurs (24 juillet), l'approbation n'ouvre plus le
      compte directement** : elle émet le jeton d'activation — l'ancien
      `users_mp_link`, désormais porté par `demande_compte_depute.token` — et le
      lien `/register/{token}` par lequel le député crée lui-même son compte et
      choisit son mot de passe (le self-service du legacy restitué, mais en aval
      de la relecture ; plus de mot de passe provisoire à transmettre). Le lien
      part à l'adresse institutionnelle (MAILER_DSN, `null://` en local) et
      s'affiche à l'administrateur pour transmission manuelle. **Anti-abus** : le
      captcha du legacy n'est pas porté (pile anti-spam de déploiement) ; le
      contrôle de l'adresse, un verrou « une demande par député » et la relecture
      humaine le remplacent. **Prouvé en prod** (serveur unique) : adresse
      inconnue → refus, format invalide → refus, député avec compte → « déjà un
      compte », député sans compte → demande créée, seconde saisie → verrou,
      approbation → jeton émis, `/register/{token}` → compte `ROLE_DEPUTE` qui se
      connecte et atterrit sur `/dashboard`, jeton à usage unique annulé après
      création. L'entrée de menu vers `/admin/demandes-comptes` (un `<li>`
      gardé `ROLE_ADMIN` dans la coque `admin/base.html.twig`) a été posée à
      l'intégration, le 24 juillet — la coque était alors sous la main d'un
      autre agent, d'où le passage de relais.
- [x] **Récupération des comptes du legacy** — commande `app:import:utilisateurs`
      (23 juillet). **Le mot de passe de l'ancienne base fonctionne sur la
      nouvelle** : le legacy hache en `password_hash(PASSWORD_DEFAULT)`, du bcrypt
      (`$2y$`) repris tel quel, que le vérifieur `auto` de Symfony relit et
      ré-encode au passage (`PasswordUpgraderInterface`). **Prouvé de bout en
      bout** : un hash bcrypt d'un mot de passe connu ouvre la session, un
      mauvais échoue, le hash passe de `$2y$12$` à `$2y$13$` après connexion.
      `users.type` → rôle : `admin` → `ROLE_ADMIN`, `writer` → rédacteur, `mp` →
      rattaché au député via `users_mp.mpId` = `depute.mp_id`. Les **lecteurs
      publics (`type=''`) sont écartés** — sans député ni rôle, `getRoles()` les
      ferait rédacteurs. Connexion par identifiant **ou** e-mail
      (`loadUserByIdentifier`) ; e-mail non unique → on refuse plutôt que de
      trancher au hasard. **Attention** : le backup public de datan.fr est réduit
      à **un seul compte** (`remikel`, admin) et `users_mp` y est vide — la
      commande est vérifiée sur ce peu, à rejouer contre la vraie base des
      comptes le jour du déploiement.
- [x] **Page de connexion** — remise en parité le 23 juillet : deux colonnes,
      logo Datan, fond Palais Bourbon (`main.css`), au lieu de la carte AdminLTE
      générique. **Servie désormais à `/login`** (l'adresse qu'attendent les liens
      du site ; `/connexion`, l'adresse du portage, y redirige en 301) depuis le
      chantier des comptes lecteurs — un seul formulaire connecte lecteurs,
      rédaction et députés. Les liens « S'inscrire », « Mot de passe oublié » et
      « Demandez un compte député » sont rétablis, ces pages existant désormais.
      Connexion par identifiant **ou** e-mail, comme le legacy. Le fond du Palais
      Bourbon est un lien externe vers Wikimedia, hérité du legacy — dépendance à
      héberger un jour en propre.
- [x] **Comptes lecteurs** — **portés le 24 juillet.** `/login` (l'adresse du
      site, `/connexion` y redirige en 301), `/register` (+ `/register/{token}`),
      `/password` (+ `/password/{token}`), `/mon-compte` (+ données, mot de passe,
      suppression). **Le piège des rôles** : un lecteur n'a ni député ni rôle, et
      `getRoles()` retombait alors sur `ROLE_REDACTEUR` — il aurait ouvert la
      rédaction. Correction : un lecteur porte `ROLE_LECTEUR` **explicitement**,
      et `getRoles()` en fait la garde (l'exclusion est écrite, pas confiée aux
      données, comme pour le député). **Prouvé en prod** (serveur unique) : un
      lecteur connecté n'obtient jamais 200 sur `/admin/*` ni `/dashboard` (403),
      seulement sur `/mon-compte`, et atterrit sur l'accueil ; un rédacteur à
      `roles=[]` garde bien `ROLE_REDACTEUR` (le repli est intact). Chaque flux
      vérifié : inscription (six saisies invalides refusées en 422), connexion des
      trois familles à leur page d'arrivée, réinitialisation (jeton 1 h, usage
      unique, envoi MAILER_DSN `null://`), changement et suppression de compte.
      **Écarts corrigés et commentés** : l'énumération de comptes sur `/password`
      (message neutre, qu'un compte existe ou non) ; la suppression par simple
      lien GET (POST + CSRF) ; le jeton de réinitialisation réutilisable (usage
      unique) ; un plancher de mot de passe (8 caractères, le legacy n'en avait
      aucun pour les lecteurs). `/register/{token}` (activation d'un député) est
      branché sur `demande_compte_depute` (cf. bullet ci-dessus). **Laissé au
      déploiement** : le captcha et la pénalité anti-force-brute (pile anti-spam),
      le transport Mailjet réel. Le lien « Connexion » du pied de page de
      `base.html.twig` a été **câblé à l'intégration le 24 juillet** (clair sur
      l'accueil, obfusqué ailleurs, comme ses voisins) ; « Mon compte » est
      entré au menu de la rédaction (`admin/base.html.twig`) au même moment.
- [x] **Inscription à la newsletter** — **portée le 24 juillet** : page
      `/newsletter`, endpoint `POST /api/newsletter/create_newsletter` (adresse
      que `main.min.js` porte en dur — exception déclarée dans
      `access_control`, seul POST public sous `/api`), modale `#newsletter`
      dans `base.html.twig` et bouton de l'accueil rétabli. **L'inscription est
      cassée sur datan.fr depuis le 24 mars 2026** : `Legacy_api.php` a été
      supprimé du legacy en laissant sa route et les deux formulaires qui
      postent dessus — tout envoi tombe en 404 et l'internaute reçoit « Vous
      êtes sans doute déjà inscrit ! » (vérifié en vivant : l'API répond 404
      sur un endpoint de lecture). Défaut corrigé au portage, commenté dans
      `NewsletterController`. Les appels Mailjet du legacy (courriel de
      bienvenue, listes de contacts) ne sont pas portés : configuration de
      déploiement. **Restent** : `/newsletter/edit/{email}` et `/delete`,
      dépendants de Mailjet, et l'envoi mensuel (`newsletter/votes`, CLI).

### Récupération des données non regénérables

Tout ce que la production détient et que l'open data ne republie pas est à
préserver. La base de production vit dans le conteneur `datan-db` ; chaque
commande porte sa requête d'export en docblock. **Un import de récupération
réaligne sur la production : une fois l'application devenue la source (un député
qui écrit, un rédacteur qui publie), ne pas le rejouer sans y penser, il
écraserait les écritures locales.**

- [x] **Comptes** (`app:import:utilisateurs`) et **explications de vote**
      (`app:import:explications`) — voir ci-dessus et §3. Les explications sont
      remontées à **45** (contre 40) : l'import initial ratait les **5 du vote du
      Congrès** (inscription de l'IVG, L16) faute de résoudre le numéro
      sentinelle `-1` ; la commande passe par le décryptage, qui porte le bon
      `scrutin_id`.
- [x] **Blog** (`app:import:articles`) — 3 rubriques + **18 articles** récupérés
      dans les tables `article` / `categorie_article` (entités dédiées, HTML
      déséchappé). L'auteur ressort vide sur le backup public (ses rédacteurs
      n'y sont pas), il se résoudra sur la vraie base. **Les pages publiques
      sont portées depuis le 23 juillet** (voir §3), images de production
      comprises.
- [x] **FAQ** (`app:import:faq`) — **6 catégories et 11 questions** récupérées,
      10 publiées, dans `faq_categorie` / `faq_post`. Le corps HTML est déséchappé
      comme le blog. L'auteur n'est pas repris : la page ne l'affiche pas et
      `created_by = 18` ne résout rien dans le backup réduit. Voir §3 pour la page.
- [x] **Quiz** (`app:import:quiz`) — **30 lignes lues, 1 écartée** (le brouillon de
      test id 34, `quizz = 0`), **29 questions récupérées**, 20 publiées, dans
      `question_quiz`. Le scrutin visé est gardé en `(scrutin_numero, legislature)`
      pour un rapprochement ultérieur, la catégorie par son slug (`fields`). Texte
      brut, aucun déséchappement. `sujets` et `profession_foi` restent vides dans ce
      backup. La page `/questionnaire` reste à porter (§3).
- [x] **Parrainages 2022** — `parrainages` (13 427) récupérés le 24 juillet
      (`app:import:parrainages`, entité `Parrainage`), 0 écarté. **Regénérable
      depuis l'open data du Conseil constitutionnel** — noté au docblock — mais
      repris de la production, qui les tient nettoyés et adossés aux acteurs
      (`mpId`). Sert la page `/parrainages-2022` (§3). Import de récupération, hors
      `app:sync:quotidien`.
- [ ] **Campagnes de dons** (`campaigns`) et **newsletter** (`newsletter`) :
      **vides** dans ce backup — rien à récupérer ici, à revérifier sur la vraie
      base. Les tables cibles existent depuis le 24 juillet (`campagne`,
      `newsletter`, colonnes du legacy sous des noms français) : au déploiement,
      transvaser les abonnés réels avant l'ouverture.
- **Note** : `questions` (17 328 lignes) n'est pas le quiz mais très
  probablement les questions au gouvernement — de l'open data, regénérable ;
  à confirmer avant d'en faire un chantier de récupération.

## 5. Ornements du gabarit

- [x] **Plus aucun lien mort dans `base.html.twig`** — « Connexion » (pied de
      page), le dernier, a été câblé le 24 juillet à la livraison des comptes
      lecteurs : `path('connexion')` (soit `/login`), en clair sur l'accueil et
      obfusqué ailleurs comme ses voisins (vérifié : `sdfghj/ybtva` = `/login`
      en ROT13). Tous
      les autres sont câblés depuis le
      24 juillet : « S'inscrire à la newsletter » (nav, avec son icône),
      « Élections » (nav), « Newsletter » (pied de page), puis « Simulateur
      coalition » (nav), « Simulateur Assemblée » et « Parrainages 2022 » (pied
      de page) sitôt les pages livrées par l'agent élections. La **barre de
      recherche de l'accueil** (carte avec le mot qui défile) a été portée au
      passage, l'endpoint `/search_api` existant enfin : balisage de
      `home/index.php`, `dist/typed.js` + `dist/autocomplete_search.js` chargés
      sur l'accueil seul comme l'origine (`Home.php:134`), exemple aléatoire
      (député ou groupe) figé une heure par le cache, action du formulaire
      neutralisée (le legacy vise `recherche.php`, une adresse morte — le JS
      intercepte toujours). Le `href="#"` de `<link rel="shortcut icon">` est
      volontaire — il évite une seconde requête de favicon, et le legacy
      l'écrit à l'identique.
- [x] **Bloc de dons** (`partials/campaign.php`) — **porté le 24 juillet** :
      `partials/campagne.html.twig` + `GET /campaign/current_active_campaigns`
      (`CampagneController`, clés JSON du legacy car `campaigns.js` — l'actif du
      site repris tel quel — lit `campaigns[0].text` et porte l'adresse en dur).
      Inclus aux **17** emplacements du legacy portés à ce jour : les dix pages
      de classements, la fiche de ville, la fiche député, la fiche groupe, la
      liste des partis et les trois pages de votes.
      L'encart reste invisible (`d-none`) tant que la table `campagne` est vide
      — c'est le comportement du site. Les deux derniers emplacements sont
      couverts depuis le 24 juillet : `outils/coalition` (posé par l'agent
      élections avec la page) et `election/resultats_commune` (posé après sa
      livraison). Les **19** emplacements du legacy sont donc tous servis.
- [x] **Modale d'inscription à la newsletter** — **portée le 24 juillet** dans
      `base.html.twig` (formulaire `#newsletterForm` intercepté par
      `main.min.js`, états masqués par `main.css`), avec le bouton de l'accueil
      qui l'ouvre, rétabli au passage.
- **La modale de première visite n'est pas à porter** : le hook
  `generalModal.php` du legacy force `show_popup = false` (« Comment if you do
  not want to desactivate ») et son contenu est un éditorial daté (motion de
  confiance de septembre 2025). C'est un mécanisme d'annonce ponctuel,
  aujourd'hui éteint à la source — le rétablir serait un choix éditorial, pas
  un portage.
- [x] **Modale de dons et pile de suivi** — **portées le 25 juillet**, le
      classement « au déploiement » était une erreur d'inventaire : datan.fr
      sert tout cela sur chaque page. Dans `base.html.twig` :
      - **tarteaucitron** (même version épinglée du CDN, `footer.php:230-345`),
        toujours servi ; il pilote GTM et le service maison « datantracking »
        (compteur mensuel de pages en cookie → `partials/modale_don.html.twig`
        au seuil de 10 pages, au plus une fois par semaine). Correction : le
        « readmoreLink » de l'origine pointe `/cookiespolicy`, une adresse qui
        n'a **jamais existé** (404 depuis toujours) → mentions légales.
      - **Matomo** (hors consentement, config exemptée CNIL, traceur renommé
        `1337.js` anti-bloqueurs) derrière `MATOMO_URL`, **GTM** derrière
        `GTM_ID` — vides par défaut : un poste de développement ne pollue
        jamais les statistiques ; en production `MATOMO_URL=https://matomo.datan.fr`
        (serveur vérifié vivant) et `GTM_ID=GTM-K3QQNK2`. Vestiges NON repris,
        commentés : l'`onload="embedTracker()"` (fonction introuvable partout —
        ReferenceError silencieuse sur chaque page du vivant) et le compteur
        `#monthly-pages-visited-count` (aucun élément HTML ne le porte nulle
        part). Le pistage reste absent de l'iframe embarquée (choix consigné).
      - Vérifié en prod : bannière + modale présentes, Matomo/GTM absents à
        vide et présents une fois les variables posées.

## 6. Points de vigilance sur l'existant

- **L'API était ouverte en écriture à tout le monde** (corrigé le 22 juillet).
  API Platform expose POST, PATCH et DELETE dès qu'une entité porte
  `#[ApiResource]` sans liste d'opérations, et n'exige aucune authentification
  par défaut : `/api` n'était pas dans `access_control`. Vérifié, pas supposé —
  un `POST /api/explications` anonyme atteignait la base et n'échouait que sur
  un `NOT NULL`. **Un anonyme pouvait donc supprimer un décryptage**, la seule
  donnée du site qu'aucun import ne régénère. Les quatorze entités déclarent
  désormais `operations: [new Get(), new GetCollection()]` ; deux règles
  d'`access_control` rattrapent celle qui l'oublierait. À vérifier à chaque
  nouvelle entité exposée : le défaut d'API Platform est ouvert, pas fermé.
- **Le texte d'une explication n'est plus rendu en `|raw`.** Tant que la table
  était en lecture seule, les 40 lignes héritées étaient du texte contrôlé ;
  l'espace député en fait une saisie, donc une injection possible sur une page
  publique. Aucune des 40 ne contient de balise, de saut de ligne ni
  d'esperluette : l'échappement ne change rien à l'affichage. `nl2br` échappe
  avant de convertir, et couvre le jour où quelqu'un ira à la ligne.

- **`/commissions` a été conçue, pas portée.** `Commissions.php::index()` du
  legacy ne rend qu'une page « en construction » de 2,6 Ko, sans en-tête ni pied
  de page. Ne pas chercher à rendre notre page identique à datan.fr : il n'y a
  rien à imiter.
- **L'API** est servie par API Platform sous `/api`, et ne reprend pas le
  découpage du legacy (`api/tables`, `api/votes`, `api/exposes`…). Si des tiers
  consomment les anciennes adresses, il faudra des redirections.
- **Saint-Barthélemy (97701) et Saint-Martin (97801) sont interchangeables dans
  la source électorale**, qui les range indifféremment sous les départements 977
  et 978 sans que rien ne permette de trancher. `app:import:resultats-electoraux`
  émet un `[WARNING]` nommant les deux communes ; leurs chiffres sont à vérifier
  avant d'être publiés. Deux communes sur 35 720.
- **592 lignes de résultats écartées** parce que leur commune a fusionné depuis
  le scrutin : elle existe au référentiel INSEE mais plus dans le découpage
  électoral, donc plus dans `commune`. Rien à corriger, mais le chiffre mérite
  d'être surveillé — s'il gonfle, c'est `app:import:communes` qui a régressé, pas
  la source.
- **L'historique mensuel de proximité ne pondère pas par le nombre de
  scrutins.** Le graphique de `/groupes/legislature-{n}/{abrev}/statistiques`
  applique la règle d'accord mois par mois, sans seuil : septembre 2025 n'a
  qu'**un seul scrutin** en 17e législature, et toute courbe y vaut donc 0 ou
  100 %. Le mois de création d'un groupe produit le même artefact. Le site
  d'origine se comporte à l'identique — parité assumée, pas un défaut d'import.
  Si un jour ces pics gênent, la correction est un seuil (« au moins N scrutins
  dans le mois ») ou l'effectif au survol ; ce serait alors une divergence
  délibérée, à consigner. À ne pas confondre avec les **trous** des courbes,
  eux volontaires : `spanGaps: false` interrompt le trait d'un groupe qui
  n'existait pas encore ou plus, là où un zéro dirait qu'il ne votait jamais
  comme les autres.
- **22 scrutins d'amendement de la 17e législature restent sans rattachement**
  après `app:lien:scrutins`. `app:scraper:scrutins` est le seul recours, et reste
  volontairement hors de la chaîne quotidienne : c'est la seule étape qui
  interroge un site public.
- **Un import complet est mort une fois sans message** (22 juillet), sortie
  tronquée au démarrage des européennes, non reproduit sur les quatre exécutions
  suivantes. Le symptôme est exactement celui d'un `cache:clear` concurrent
  décrit dans `CLAUDE.md`, et plusieurs serveurs de développement tournaient. À
  rouvrir si cela se reproduit sans cette circonstance.
- **Balayage hors-routes du legacy (25 juillet)** — ce que `routes.php` ne dit
  pas et qui reste à la charge du déploiement ou d'un chantier dédié :
  - **`scripts/daily.php` publie des jeux CSV sur data.gouv.fr**
    (`opendata()`, téléversement par l'API) — ce sont les jeux que notre pied
    de page pointe. Publication à replanifier au déploiement, hors de
    `app:sync:quotidien`.
  - **`daily.php` moissonne aussi les comptes Bluesky** (`addBsky()`) — versé
    au chantier réseaux sociaux.
  - Courriels : le legacy compose en **MJML** (`qferr/mjml-php`) et envoie par
    **Mailjet** — newsletter mensuelle et transactionnels, au déploiement.
  - `pfaciana/tiny-html-minifier` est au composer du legacy mais **introuvable
    à l'usage** (ni hook ni core) : rien à porter.
  - Hooks : `ssl.php` (redirection https, niveau serveur au déploiement),
    `urlValidator` et `generalModal` déjà traités.
