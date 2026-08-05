# Reste à faire

État au 30 juillet 2026, après les deux passes menées à cinq les 29 et
30 juillet : tout ce qui est livré **et vérifié** a été retiré — le détail des
chantiers soldés est dans l'historique git de ce fichier. Les conventions et les
pièges métier sont dans `CLAUDE.md`. La comparaison visuelle à datan.fr est
**close** : périmètre public et écrans publics de connexion sur captures,
espace connecté profond sur le code du legacy (cf. §4).

Le périmètre de référence (`../datan/application/config/routes.php` et le
`.htaccess`) est couvert : chaque adresse publique du legacy est servie,
redirigée en 301, ou écartée pour une raison consignée au §3.

## 1. Chantiers restants

- [ ] **Professions de foi : la table est vide, les PDF manquent.** Le bloc
      « Ses professions de foi » de la fiche est porté
      (`depute/_professions_foi.html.twig`) et son import écrit
      (`app:import:professions-foi`), mais `profession_foi` est **vide dans le
      backup public** et les PDF (`assets/data/professions/election_*/`) ne sont
      pas dans nos assets. À rejouer contre la vraie base au déploiement (§2),
      avec la copie du répertoire d'assets. D'ici là le bloc reste masqué.
- [ ] **HATVP : dernier métier déclaré.** Le legacy ajoute au bloc métier un
      paragraphe et une modale sur les activités déclarées à la HATVP. La table
      `hatvp` du backup public est **vide (0 ligne)** : même traitement que les
      professions de foi.
- [ ] **Le bloc auteur de la page de vote n'a que sa variante amendement.** La
      carte « L'auteur de l'amendement » (député ou Gouvernement) est portée le
      4 août — la donnée ne demandait **aucun scraping**, contrairement à ce que
      ce fichier affirmait : `amendementsAuteurs()` (daily.php:3753) lit les
      archives XML de l'open data, et le dépôt Tricoteuses des amendements porte
      la même structure `signataires.auteur` en JSON
      (`app:import:auteurs-amendements`, hors sync, à rejouer après un import
      d'amendements). Restent les deux variantes du même bloc que sert datan.fr
      sur les votes **sans** amendement : « Le rapporteur » et « L'auteur de la
      proposition de loi » (`Votes::index`, `get_dossier_mp_authors` /
      `get_dossier_mp_rapporteurs`) — elles demandent les initiateurs et
      rapporteurs de dossier (`dossiers_auteurs`, `documents_legislatifs`), que
      nous n'importons pas encore ; l'acte `AN1-COM-FOND` des dossiers
      Tricoteuses ouvre peut-être la voie, comme pour `commission_fond`.
- [ ] **La participation d'un groupe sur une page de vote diverge d'un point.**
      Sur le scrutin 8409 de la 17e, EPR sort à 13 % chez nous contre 12 % sur
      le site, LFI-NFP à 23 % contre 21 % — mêmes numérateurs (12 et 16), donc
      un dénominateur différent : nous divisons par
      `vote_groupe.nombre_membres_groupe` (91 et 71, la ventilation publiée par
      l'Assemblée), le site par un effectif plus large (~97 et ~75, sans doute
      sa table `groupes_effectif`). Écart de calcul, pas d'affichage — à
      trancher avec les autres reprises de `vote_groupe`.
- [ ] **Statistiques d'une législature passée (`/legislature-N`) : reste la
      carte de la majorité.** Les votes nominatifs des
      législatures 14 à 16 sont importés le 4 août (dépôts
      `Scrutins_XIV/XV/XVI_nettoye`, 111 244 + 475 212 + 604 815 lignes, zéro
      écartée — `vote` couvre désormais 2,46 M de lignes sur les quatre
      législatures), et les calculs dérivés sont rejoués **par législature**
      (`app:calcul:statistiques-deputes --legislature=N`, qui ne réécrit que la
      sienne ; `statistique_depute` porte désormais le `groupe_id` de l'époque).
      `ComportementDepute` sait servir une législature close : moyennes sans
      filtre d'activité, barres de proximité sur tous les groupes et sans phrase
      éditoriale — les règles du `Depute_service` du legacy. Vérifié contre le
      site vivant sur Bernalicis : 15e (95 %, moyennes 91/96, loyauté 100,
      moyennes 94/99) et 16e (95 %, moyenne 83 ; loyauté 100, moyennes 96/99 ;
      proximités LFI-NUPES 100/894, ECOLO 88, HOR 19, RE 19, DEM 20) — tout au
      point près, sauf SOC-A 78 contre 79 (un vote sur 140, famille des chiffres
      d'époque figés du §4, ne pas courir après). Les pages de vote 14-16
      servent déjà leur détail nominatif (vote 1200 de la 15e : 24 contre /
      6 pour, identique au site). Le branchement d'affichage est fait le
      4 août : `DeputeController::legislature()` passe `statistiques` (groupe
      de l'époque) et `depute/legislature.html.twig` rend le bloc — vérifié sur
      Bernalicis 15e et 16e, mêmes chiffres que la validation ci-dessus. Reste,
      pour la parité complète de cette fiche, la carte « Proximité avec la
      majorité gouvernementale » des 14e-16e, qui demande un équivalent de
      `class_majorite` (la 17e n'a pas de majorité déclarée, cf. CLAUDE.md).
- [ ] **Les photos détourées de datan.fr ne sont pas dans ce dépôt.** Le site
      sert un portrait détouré, recadré carré en 240 × 240 depuis la 17e
      (`assets/imgs/deputes_original/`) ou en 150 × 192 avant
      (`assets/imgs/deputes_nobg/`), plus leurs variantes webp. Nous n'avons que
      le portrait brut de l'Assemblée publié par `app:import:photos` : le cadre
      carré le rezoome sur le visage, ce qui se voit sur **chaque carte du
      site**, et les législatures passées tombent presque toutes sur le visage
      générique — le dépôt des Tricoteuses ne couvre que les députés actuels.
      `PhotoExtension` cherche désormais les jeux détourés en premier : le jour
      où les dossiers sont là, la parité est acquise sans toucher au code. Ils
      pèsent une centaine de mégaoctets, ne se régénèrent pas (détourage fait à
      la main) et ne vivent que sur le serveur : **à recopier au déploiement**,
      comme les PDF des professions de foi.
- [ ] **68 scrutins de la 17e restent sans rattachement complet** (20 sans
      amendement, 48 sans dossier) après `app:lien:scrutins` — deux amendements
      gagnés le 4 août par arbitrage manuel (`RATTACHEMENTS_ARBITRES` de
      `LienScrutinsCommand`, avec 7 dossiers corrigés et le vote du Congrès).
      Le recours, `app:scraper:scrutins --relance`, rejoué le 30 juillet puis
      le 4 août : 68 pages visitées, **zéro gain** — 17 pages sans aucun lien
      d'amendement, 3 numéros discordants, et les pages des sans-dossier
      n'offrent rien non plus. L'écart est chez la source : l'Assemblée n'a pas
      complété ses pages. À relancer de loin en loin, rien à corriger chez nous.
- [ ] **`simplicite_ia`** : la colonne « Simplicité » de l'écran des amendements
      s'affiche « — » tant que la génération IA ne la produit pas (le legacy la
      rend en étoiles 1-5). À brancher dans `app:ia:resumes-amendements` le jour
      où le modèle de production est choisi.
- [ ] **Résumés d'amendements en lot** (`app:ia:resumes-amendements`) : à lancer
      sciemment **après** le choix d'`IA_MODELE` de production — la garde
      « jamais écraser un résumé existant » fait qu'un lot passé au modèle
      d'essai local bloquerait pour toujours un lot plus propre.

## 2. Au déploiement

Rien à coder d'avance ; à dérouler le jour J, dans cet ordre de préférence.

- [ ] **Variables de production** : `MATOMO_URL=https://matomo.datan.fr`,
      `GTM_ID=GTM-K3QQNK2`, `MAILER_DSN` réel, `IA_MODELE` — et la clé
      `ANTHROPIC_API_KEY` dans `.env.local`, **jamais** dans `.env`. Revérifier
      alors le texte des mentions légales avec la pile de suivi réellement active.
- [ ] **Rejouer les imports de récupération contre la vraie base** — le backup
      public est réduit et anonymisé : `app:import:utilisateurs` (tous les
      comptes, `users_mp`, mots de passe repris tels quels), les auteurs du
      blog, `campaigns` et `newsletter` (vides dans le backup — **transvaser les
      abonnés réels avant l'ouverture**), `app:import:professions-foi` **avec la
      copie du répertoire `assets/data/professions/`**, et les décryptages
      (`app:import:decryptages`, 250 au 15/07/2026 dans le backup public — la
      vraie base en a davantage). Un import de récupération écrase les écritures
      locales : à ne jamais planifier.
- [ ] **Purger les comptes d'essai** : `redaction`, `editeur`, `guibert`.
- [ ] **Courriels** : le legacy compose en MJML (`qferr/mjml-php`) et envoie par
      Mailjet — newsletter mensuelle (`newsletter/votes`, CLI), transactionnels,
      `/newsletter/edit/{email}` + `/newsletter/delete`, courriel d'activation
      du compte député (aujourd'hui le lien s'affiche à l'administrateur pour
      transmission manuelle).
- [ ] **Pile anti-spam** : captcha et pénalité anti-force-brute (inscription,
      demande de compte député, mot de passe oublié).
- [ ] **Publication data.gouv** (`opendata()` de `daily.php`) : les jeux CSV que
      notre pied de page pointe. À replanifier, hors `app:sync:quotidien`.
- [ ] **Planification** : `bin\planifier-sync.ps1` (sync quotidien) ;
      `app:calcul:statistiques-deputes` **après chaque import de votes** (hors
      sync) ; `app:moissonner:bluesky` à la demande.
- [ ] **Niveau serveur** : redirection https (le hook `ssl.php` du legacy y est
      resté) ; si des tiers consomment l'ancienne API (`api/tables`,
      `api/votes`, `api/exposes`…), poser des redirections — API Platform ne
      reprend pas ce découpage.
- [ ] **Héberger en propre le fond du Palais Bourbon** : la page de connexion
      pointe un fichier Wikimedia, dépendance héritée du legacy.

## 3. Abandonné sciemment — ne pas y revenir

- **Le maire d'une commune** : `cities_mayors` vide dans notre copie, et la
  donnée de production a dérivé (« Hubert De jenlis »). Une donnée fausse ne
  vaut pas mieux qu'une absente.
- **Les tables `elect_bv_*`** (grain bureau de vote, jamais écrites) et les
  **résultats municipaux du ministère** (`elect_municipales_*`) : le chantier en
  cours du legacy, pas une donnée à porter. (Les **candidatures** municipales
  des députés, elles, sont importées depuis que la production a ajouté
  l'élection `id = 7` à son catalogue — cf. §1 pour les encarts de fiche qui
  restent à porter.)
- **La modale de première visite** : éditorial daté, codé en dur, éteinte à la
  source. (Le **`voteFeature`** — l'encart « dernier vote important » — est en
  revanche bien porté : datan.fr l'affiche, il n'était pas éteint. Cf. §4.)
- **La phrase « famille professionnelle » de la bio** : présente dans la vue du
  legacy (`_bio.php:110`), mais `Deputes.php:216` a commenté l'appel qui la
  nourrit et passe `null` — datan.fr ne l'affiche donc jamais (vérifié sur le
  site vivant le 30/07/2026). L'omettre est la parité, et `ProfilSocial` portant
  la donnée n'y change rien : même famille d'extinction à la source que la
  modale ci-dessus. Commenté dans `depute/_bio.html.twig`.
- **`/commissions`** : la page du legacy est un « en construction » de 2,6 Ko
  sans en-tête ni pied — rien à imiter. La nôtre est donc une création propre,
  sans référence : ce qui s'y casse est un défaut de ce dépôt, pas un écart de
  portage, et ne se compare à rien.
- **Le générateur d'iframe du dashboard député** : `show_404()` à la source.
- **`admin/api-keys`** (caduc : le seul client était PoliticAnalysis, la
  génération est interne depuis le 24 juillet), **`admin/elections/*`**
  (fenêtre de candidatures close depuis 2022), **`admin/votes`** (doublon du
  CRUD des décryptages).
- **`get_questions_api` du quiz** (branché sur aucune route) et la table
  **`questions`** parlementaires (code mort des deux côtés ; si une page naît
  un jour, c'est un import open data Tricoteuses, pas une récupération).
- **`/redirect/cities/{code}/{dpt}`** : 404 sur datan.fr même.
- **`pfaciana/tiny-html-minifier`** : au composer du legacy, introuvable à
  l'usage.

## 4. Points de vigilance sur l'existant

- **La fiche d'un ancien député se reconstitue par `fonction_groupe`, jamais par
  `depute.groupe_id`.** La colonne ne porte que l'appartenance courante : les
  1 545 fiches d'anciens sortaient sans liseré, sans groupe sur leur carte de
  profil, sans la phrase « a siégé avec le groupe … » et sans moyennes de
  groupe, là où le site garde le dernier groupe connu dans
  `deputes_last.groupeId`. `DeputeController::dernierGroupe` le résout (dernier
  rattachement principal), et deux paragraphes manquaient au gabarit : la sortie
  de l'Assemblée avec son motif et le rattachement financier au passé. Deux
  branches de `Depute_edito::get_end_mandate()` **oublient leur `return`**
  (démission, annulation de l'élection) : le site publie « … le 09 avril 2026 . »
  sans motif sur 113 fiches ; le motif est rétabli ici, oubli d'écriture et non
  choix éditorial.
- **Un exposé d'amendement peut ouvrir par `<p style="…">`, et la garde le
  ratait.** Le gabarit de la page de vote choisissait entre `|raw` et texte
  échappé sur la présence de la chaîne `'<p>'` — chevron fermant compris :
  2 359 des 7 200 exposés mis aux voix ouvrent par `<p style="text-align:…">`
  et sortaient donc en HTML visible, balises et entités en toutes lettres.
  Le test porte désormais sur `'<p'`. À se rappeler pour toute garde qui
  reconnaît du HTML à une balise nue.
- **Une cellule de cohésion vide n'est pas un zéro.** Un groupe dont personne
  n'a voté (« Non votant ») affiche une cellule vide sur datan.fr ; nous
  écrivions `0.000`, ce qui affirme un groupe parfaitement désuni là où il n'y
  a rien à mesurer. `VoteController::groupBreakdown` rend `null` sur zéro
  exprimé depuis le 4 août.
- **`VOTES_CLES` est une sélection éditoriale vivante — la resynchroniser sur le
  legacy, pas la deviner.** Le 4 août 2026, `Votes_model::get_key_votes_mp()` et
  le site vivant servaient quatre scrutins, tous 17e (3260, 3300, 8280, 8427),
  rendus par numéro croissant ; les deux scrutins 16e d'une sélection antérieure
  (IVG 629, immigration 3213) ont été retirés par la rédaction —
  `app:import:votes-cles` et ses votes restent en base, sans consommateur
  jusqu'aux fiches des législatures passées. La préposition doublée « en faveur
  de du projet de loi » est morte avec le texte qui la portait, mais la garde de
  composition reste dans `_positions.html.twig` : un texte en « du … » se
  contracte en « en faveur du … », datan.fr publiait le doublon.
- **Le pied de la fiche de groupe de datan.fr parle de la législature
  précédente — pas nous.** Sur `/groupes/legislature-17/lfi-nfp`, le site titre
  « Les groupes parlementaires de la 16ème législature » au-dessus de liens qui
  pointent tous vers la 17e, et son bloc d'apparentés renvoie vers
  `legislature-16/lfi-nupes/membres` : sa variable `$groupe` a été écrasée par
  l'incarnation précédente du groupe avant `mps_footer.php`. Notre transcription
  du même gabarit est fidèle et rend, elle, la législature de la fiche. Écart
  visible sur capture, à ne pas « corriger » vers le site.
- **L'encart « Municipales 2026 » d'un groupe compte des députés EN EXERCICE.**
  Règle de l'accueil (visible + candidat + mandat 17e ouvert) : LFI-NFP 50 chez
  nous contre 51 sur le site, dont le comptage (`get_n_candidates_by_group`,
  `deputes_last.groupeId` sans filtre d'activité) garde un ex-député — David
  Guiraud, parti de l'Assemblée. Sa propre page d'accueil compte 311 comme
  nous. Divergence assumée : la phrase dit « députés membres du groupe ».
- **« de la commune X » de l'encart municipales : l'article est recomposé, avec
  deux corrections.** La colonne `nom_de` des `cities` du legacy n'est pas dans
  notre référentiel : `DeputeController::communeAvecDe/AvecA` la refont (« du
  Havre », « aux Abymes », « d'Aix-en-Provence »). Corrigés au passage, avec le
  commentaire d'usage : « à La Rochelle » et « à L'Aigle », où le site perd
  l'article (« à Rochelle », « à Aigle » — famille « à la La Réunion »). Une
  divergence de donnée demeure : le site écrit « d'Évry-Courcouronnes » (sa
  colonne `nom_de` suit un millésime INSEE plus récent) là où notre référentiel
  — et sa propre fiche de ville — disent « Evry ».
- **La position majoritaire d'un groupe se RECALCULE, elle ne se reprend pas.**
  Le champ `positionMajoritaire` publié par l'Assemblée ne départage que le
  « pour » et le « contre » : un groupe à 4 pour / 1 contre / 17 abstentions y
  est déclaré « pour ». Le site calcule depuis toujours la pluralité stricte sur
  les trois positions (`daily.php:1439-1449`) — égalité ou personne d'exprimé →
  `nv`. Avoir repris le champ publié avait faussé **6 983 ventilations** (dont
  4 945 en 17e) et, par ricochet, toutes les loyautés, proximités et classements
  du site (Bernalicis à 98 % au lieu de 100 %). La règle vit dans
  `ImportScrutinsCommand::ligneVentilation` : ne pas la « simplifier ».
  **Après toute reprise de `vote_groupe`, relancer
  `app:calcul:statistiques-deputes` et `app:calcul:classements`**, qui en
  dérivent.
- **Le correctif SOC de la 16e vit dans `ImportScrutinsCommand`, comme celui de
  la position majoritaire.** L'Assemblée publie une partie des ventilations du
  groupe socialiste sous `PO800496` après son renommage en SOC-A (`PO830170`)
  le 19/10/2023 : 581 ventilations réattribuées le 30 juillet (purge des
  lignes fausses **avant** réimport de la 16e — l'upsert ne supprime jamais,
  cf. la règle des imports). Vérifié contre le site : participation SOC 18 %,
  SOC-A 19 %, cohésion 0,95, courbes SOC closes en octobre 2023, moyennes
  d'assemblée 21 % / 0,94 identiques. Rejeu :
  `app:import:scrutins --depot=var/tricoteuses/scrutins-xvi --tout --sans-votes`
  (clone conservé sur place). Reste **8 ventilations SOC-A antérieures au
  19/10** — l'anomalie inverse, que le correctif du legacy ne traite pas non
  plus : parité.
- **La base du conteneur `datan-db` est un instantané, comme le backup
  public.** Son `daily.php` s'est arrêté au **13 mai 2026** (scrutin n° 6530) :
  toute mesure qui y est prise date de ce jour-là, et conclure de cette copie
  à l'état de datan.fr est une faute — commise puis rattrapée le 30 juillet :
  la proximité EPR ↔ DEM y vaut 87 % quand le site vivant, à jour, affiche
  88 %. La copie reste précieuse pour une chose que le site ne donne pas :
  exécuter le vrai code du legacy sur des données connues. C'est ainsi que
  notre formule de proximité est **prouvée identique** à la leur — sur la
  fenêtre EPR ↔ UDR, close des deux côtés (3 041 scrutins), les deux calculs
  donnent 0,4495 exactement.
- **Les proximités de groupe de la 16e sont des chiffres d'époque, figés — ne
  pas courir après.** `class_groups_proximite` est reconstruite chaque nuit en
  `DROP + CREATE` depuis `groupes_accord`, qui ne porte que la législature
  courante : depuis le passage à la 17e, le code du site **ne peut plus
  produire** les proximités de la 16e — ses pages 16e servent, via le cache de
  sortie CodeIgniter, des chiffres calculés à l'époque sur un état des données
  d'alors. D'où les écarts restants, tous 16e : SOC ↔ ECOLO 68 % chez nous
  contre 75 % figés, SOC-A ↔ ECOLO 75 % contre 78 %, moyenne de proximité à la
  majorité 51 % contre 52 %. Nos valeurs sont le recalcul complet sur la
  législature achevée, correctif SOC compris. Sur la 17e, vivante des deux
  côtés, site et recalcul coïncident (EPR ↔ DEM 88 %, vérifié le 30 juillet).
  Même famille que `coalitions_groupes` jamais vidée (ci-dessous) : l'histoire
  de leurs tables n'est pas reproductible, et ce n'est pas un défaut de
  portage.
- **Un pourcentage se refait sur ses entiers, jamais sur `classement.score`.**
  La colonne est un `decimal(8,3)` : les 45 femmes d'EPR sur 91 sièges y sont
  stockées `0,495` et ressortent à 50 % au lieu de 49. Ce double arrondi
  déplaçait d'un point 38 taux de loyauté et 32 de participation.
  `ClassementController` refait donc le calcul sur `numerateur`/`denominateur`,
  que la table garde en entiers — **et trie de même**, par produit croisé : sans
  cela trois députés à 100 % partagent le rang 1 quand le site les sépare
  (26/26, 2 783/2 783, 2 406/2 407). La même règle laisse en revanche la
  participation ex æquo au rang 1, les 72 solennels étant communs à tous : c'est
  bien ce qu'affiche le site.
- **Le classement d'âge est le seul des neuf à ne pas passer par `RANK()`.**
  `stats_model.php:29` y numérote au fil de l'eau : deux octogénaires du même
  âge portent 1 puis 2, et le dernier porte 577. Ne pas « harmoniser » avec les
  huit autres.
- **`Stats::index()` n'applique pas le seuil que la page dédiée applique.** La
  participation des groupes lue sur l'index des statistiques (RN 34 %,
  LIOT 12 %) n'est donc pas celle de la page dédiée (75 %–95 %). Nous appliquions
  le seuil aux deux ; les deux valeurs sont désormais reproduites telles quelles.
  Parité assumée, aussi déroutante soit-elle.
- **Un ex æquo sans départage change de gagnant d'un rendu à l'autre.** UDDPLR
  et GDR comptent tous deux 9 cadres sur 17 sièges : sans second critère, la
  carte en vis-à-vis désignait tantôt l'un, tantôt l'autre. Départage par sigle
  — ce qui aligne au passage sur l'affichage du site. À vérifier partout où une
  carte prend le premier d'un tri. **Même famille sur les barres « vote
  rarement avec »** : elles se prenaient en retournant la fin du tri
  décroissant, ce qui laisse l'ordre des ex æquo au hasard du tri. Bernalicis
  en 16e (HOR 19, RE 19, DEM 20, LR 20) sortait RE, HOR, LR contre HOR, RE, DEM
  sur le site. `ComportementDepute::accordGroupes` refait donc un tri croissant
  avec le même départage par sigle.
- **La moyenne de cohésion du site ne correspond pas à ses propres lignes.**
  `get_stats_avg()` moyenne `class_groups` sans reprendre le `active = 1` du
  tableau affiché : 0,927 annoncé au-dessus de douze lignes qui donnent 0,925.
  Même famille sur la participation des députés : le site moyenne toutes les
  lignes, anciens députés compris, sous un tableau qui n'affiche que les 577 en
  exercice — ses moyennes sortent un point sous les nôtres (89 % et 25 % contre
  90 % et 26 %). Règle : **nous moyennons ce que nous montrons** — divergence
  assumée, elle corrige. Les moyennes de groupes tombent juste des deux côtés.
- **La période de présence d'un député se lit dans `fonction_groupe`, jamais
  dans `mandat`.** L'Assemblée ne garde qu'un mandat par siège et **remplace**
  l'ancien au lieu de l'archiver : Thierry Liger n'a qu'un mandat pris le
  22/04/2026 et cinquante votes solennels dès février 2025 ; Patrick Hetzel un
  mandat couvrant toute la législature alors qu'il était ministre d'octobre à
  janvier. 26 députés de la 17e ont des votes hors de leur mandat déclaré — six
  sortaient à plus de 100 % de participation. Le rattachement de groupe, lui,
  se referme et se rouvre à chaque aller-retour : c'est sur lui que reposent,
  depuis le 30 juillet, la participation (bornes de présence) comme la loyauté
  (rattachement le plus récent, principal, votes bornés à ses dates —
  68 des 645 votants de la 17e perdaient toute leur loyauté quand elle se
  calculait sur `depute.groupe_id`, cf. le piège de `CLAUDE.md`).
- **Participation aux solennels : le non-votant sort du dénominateur.**
  Présider la séance ou siéger au Gouvernement interdit de voter — ce n'est pas
  une absence (Braun-Pivet, non-votante sur 49 des 72 solennels, vaut 23/23 :
  100 %, rang 16, comme le site). L'exception `PA721908` en dur de
  `daily.php:2306-2318` n'est **pas** reprise : notre base reçoit ces scrutins
  en `nonVotant` (72 lignes pour 72 solennels, aucun trou), le cas général
  couvre le cas particulier et survivra au prochain président — consigné dans
  le docblock de `CalculClassementsCommand`.
- **« Votes par spécialisation » : trois écarts assumés face au site, aucun à
  « réparer ».** Le troisième score de participation (livré le 4 août :
  `dossier.commission_fond` depuis l'acte AN1-COM-FOND, types
  `deputes/groupes_participation_commission`, onglet sur les deux pages) sort
  les mêmes têtes de classement et les mêmes scores à ±1 point que datan.fr,
  mais : nos « nombre de votes » dépassent les siens de ~3 % — sa table
  `votes_participation_commission` est incrémentale et jamais revisitée, un
  scrutin rattaché à son dossier après coup lui échappe pour toujours, quand
  notre recalcul le voit (8 243 scrutins couverts contre ~7 275) ; son tableau
  des députés liste 584 lignes pour 577 sièges — neuf réélus y figurent en
  double, sa jointure ne filtrant pas la législature (défaut corrigé) ; et la
  présidente de l'Assemblée apparaît chez nous, pas chez lui (exception
  `PA721908` non reprise, comme pour les solennels). Détail dans le docblock de
  `CalculClassementsCommand::participationCommission`.
- **L'égalité de rang d'un groupe se juge sur le score stocké, un
  `decimal(6,3)`.** Le `RANK()` d'origine porte sur `class_groups.value` : deux
  groupes séparés à la quatrième décimale sont ex æquo pour le site (UDDPLR et
  GDR au rang 3, EPR et DEM au rang 6). Juger sur le flottant brut les
  séparait. Les rangs stockés des députés portent désormais eux aussi des
  ex æquo (1, 1, 1, 4…) — sans effet à l'affichage, le contrôleur recalculant
  sur les entiers, mais visible en interrogeant la table.
- **L'indice de Rose du site perd ses artisans, pas le nôtre — divergence
  choisie.** `famsocpro` écrit « Artisans, commerçants et chefs d'entreprise »,
  l'open data « Artisans, commerçants, chefs d'entreprises » : l'appariement à
  égalité stricte du legacy fait disparaître les 41 artisans de la 17e du
  calcul (ni numérateur ni dénominateur) quand son propre tableau croisé les
  affiche. Retirer nos artisans reproduit le site au millième (LFI 0,432,
  RN 0,403, sept groupes sur onze) : cause démontrée, docblock à l'appui. Des
  députés bien classés valent mieux que la parité au chiffre — nos scores
  restent un à quatre centièmes au-dessus, et c'est une ligne à changer si la
  parité stricte est préférée un jour.
- **Un non-votant s'affiche « abstention » dans les positions importantes, mais
  la comparaison au groupe se fait sur la position brute.** Le comportement du
  legacy est un accident heureux : `CASE WHEN vs.vote = 0` compare un varchar à
  un entier, MariaDB convertit `'nv'` en 0 → « abstention », et le
  `scoreLoyaute` reste NULL → « n'a pas voté comme son groupe ». Notre
  `ComportementDepute` reproduit le résultat proprement : la normalisation ne
  touche que l'affichage — la décider sur la position normalisée ferait passer
  pour loyal un non-votant dont le groupe s'est abstenu.
- **La moyenne de proximité à la majorité du site (46 %) sort d'une
  auto-jointure sans garde** : le couple (RE, RE) vaut 1, la majorité pèse
  ~100 % dans sa propre moyenne. Reproduit à contrecœur (le chiffre propre
  serait 51 %), commenté dans le code — c'est une parité, pas une erreur à
  « réparer ».
- **La page statistiques d'un groupe recalcule à la volée ce que le site
  précalcule la nuit** (sept tables `class_groups*` / `groupes_*_history` que
  nous n'avons pas) : tout sort de `vote_groupe`, `fonction_groupe` et
  `depute`, le cache HTTP tenant lieu de précalcul. Si cette page devient
  lente à froid, c'est ici — et la réponse est un précalcul, pas un
  appauvrissement de la page.
- **`datetime-moment.js` se charge APRÈS `datatable-datan.min.js`, jamais avant.**
  C'est un greffon de DataTables : il pose `$.fn.dataTable.moment`. Chargé avant
  la bibliothèque il échoue en silence, puis l'initialisation de
  `data-table-datan.js` meurt sur un `TypeError` — et **toutes** les tables de la
  page perdent recherche, tri et pagination sans la moindre trace côté serveur.
  `/votes/legislature-17` rendait ainsi ses 8 434 lignes d'un bloc, et les
  **neuf pages de classement** perdaient recherche et tri d'un seul coup.
  **Trois** gabarits avaient l'ordre inversé — `vote/all`, `parrainages/index`
  et `classement/_layout` —, corrigés le 29 juillet ; `groupe/votes_tous` et
  `depute/votes` étaient justes. **`vote/individual` n'avait, lui, ni l'un ni
  l'autre** : ses deux tables de scrutin (groupes, députés) sortaient sans tri
  ni recherche sur **toutes** les pages de vote, l'initialisation mourant sur
  `$.fn.dataTable.moment` introuvable — variante muette du même défaut, trouvée
  le 4 août en comptant les `dataTables_wrapper` du DOM rendu (`chrome
  --headless --dump-dom`, 0 attendu 2). Le symptôme ne ressemble pas à une
  erreur de script : la page s'affiche, simplement sans ses commandes. Le
  défaut ayant été trouvé quatre fois indépendamment, le vérifier reste le
  premier réflexe devant un tableau sans barre de recherche — et le compte des
  `dataTables_wrapper` le tranche sans ouvrir un navigateur.
- **La composition d'une législature achevée est incomplète dans notre source.**
  La phrase « il y avait à l'Assemblée nationale N hommes et M femmes » compte,
  comme le legacy, les mandats dont la prise de fonction est le **jour
  d'ouverture** (`Legislature::ouverture()`) — et non les 618 à 651 personnes qui
  ont siégé, qu'affichait notre première version. Mais notre source n'en porte
  que 567 pour la 16e, 560 pour la 15e, 543 pour la 14e, là où datan.fr tombe
  chaque fois sur les **577 sièges**. L'écart croît avec l'ancienneté : signature
  d'un jeu de données de l'Assemblée qui remplace les mandats invalidés
  (annulations, ministres jamais installés) au lieu de les conserver, quand le
  legacy garde son instantané de l'époque. Les proportions, elles, tombent juste
  aux 15e et 16e (61/39 et 63/37) et divergent de deux points à la 14e. Reprendre
  les mandats depuis un instantané ancien serait le seul remède — pas un défaut
  de portage.
- **Un slug de département en casse mixte doit répondre, pas rendre 404.**
  `/deputes/corse-du-sud-2A`, `/deputes/NORD-59`,
  `/deputes/corse-du-sud-2A/ville_ajaccio` répondent 200 sur datan.fr — routeur
  CodeIgniter permissif, collation MariaDB indifférente à la casse. Plutôt que
  de servir la même page sous une infinité d'adresses, les motifs acceptent
  désormais les majuscules et les quatre contrôleurs **redirigent en 301** vers
  l'orthographe de `departement.slug`. Les exclusions `legislature-` et
  `inactifs` ont dû être rendues insensibles à la casse, sans quoi elles volent
  leurs routes à `DeputeListController`.
- **Décoder les entités HTML APRÈS la troncature d'un extrait, jamais avant.**
  `strip_tags()` ôte les balises mais laisse les `&nbsp;`, que l'échappement de
  Twig rendait ensuite en toutes lettres. Le `word_limiter` du legacy compte
  `&nbsp;Si` comme **un** mot, deux une fois décodé : décoder d'abord
  déplacerait les frontières et changerait les 18 extraits du blog.
- **Les trois méta-descriptions sortent en `|raw`, et l'équation est calibrée.**
  Le bloc `meta_description` est échappé une fois par l'autoéchappement de
  Twig ; le `|trim` de la coque perd le marquage « safe » (mesuré : le passage
  par une variable suffit aussi), donc l'insertion ré-échappait. Le `|raw`
  supprime le second échappement, pas le premier — l'audit des 49 blocs le
  garantit (aucun `|raw`, aucun HTML, aucune entité en dur dedans), et
  **l'équation tient tant qu'aucun bloc n'injecte du contenu non échappé**. La
  clé `ogp.description`, qu'aucun contrôleur ne renseigne encore, s'échappe à
  la source pour arriver dans le même état que la branche du bloc.
- **Adjacence des communes : 6 388 couples écartés est le chiffre nominal.**
  6 342 portent sur 1 559 communes fusionnées que le référentiel ne connaît
  plus (Maine-et-Loire, Calvados, Manche, Orne en tête), 46 sur neuf communes
  sans circonscription — les six villages détruits de Verdun, Sannerville,
  L'Oie, Sainte-Florence. Le total ferme à l'unité :
  218 852 = 212 464 + 6 388. Et **279 communes sans aucune voisine est
  correct** : 224 villes de l'étranger (099), 55 îles. Si ces chiffres bougent,
  c'est l'import qui a régressé. L'index PHP se fait en minuscules et
  l'orthographe rendue est celle de la base — même modèle
  qu'`ImportResultatsElectorauxCommand`, qui faisait déjà bien.
- **Des « HTTP 000 » par centaines d'affilée = ports éphémères de Windows
  épuisés**, pas un site en panne : des connexions en rafale sans réutilisation
  vident la plage locale. Rejouer sur connexion persistante. À ranger avec les
  faux 500 du banc multi-`php -S` de `CLAUDE.md` : des anomalies par tranches
  contiguës accusent l'outillage, jamais le site.
- **`|default()` remplace aussi les valeurs vides : `false|default(true)` rend
  `true`.** Pour un booléen venu de la requête (`?secondary-title=hide`), le
  repli s'écrit `is defined`, jamais `|default()` — le titre de l'iframe
  restait affiché malgré le paramètre, et seule la capture l'a montré.
- **L'espace connecté profond est comparé sur le code, pas sur capture.** Mon
  compte, tableau de bord du député et espace de rédaction n'ont pas de compte
  sur la production : leur parité (passe du 30 juillet) est établie contre les
  vues et contrôleurs de `../datan`, rendues sous session locale. Une
  divergence entre le code du legacy et ce que sert réellement datan.fr y
  passerait inaperçue.
- **`/admin/socialmedia/{page}` n'accepte que six valeurs nommées**
  (`deputes_entrants`, `deputes_sortants`, `postes_assemblee`,
  `groupes_entrants`, `historique`, `x`) : un `{page}` numérique répond 404
  par construction — à savoir avant de conclure à une page cassée.
- **L'iframe est un document autonome.** Elle n'hérite de rien de
  `base.html.twig` : la police manquait, et le navigateur du tiers retombait sur
  une sérif locale, ce qui change toute l'allure de l'embarqué. Même bloc
  `@font-face` que la coque, comme le `_header_iframe.php` du legacy. Toute
  déclaration globale ajoutée à la coque est à répercuter ici.
- **`iframe/_positions.html.twig` est une copie de celui de la fiche.** Le
  legacy charge le même partial dans les deux contextes, deux conditions
  internes suffisant à couvrir l'embarqué ; nous ne pouvions pas le faire sans
  toucher au périmètre de la fiche, d'où deux fichiers. Une retouche de
  `depute/_positions.html.twig` est à répercuter — l'en-tête du fichier le dit,
  mais rien ne l'impose.
- **`?first-person=true` est mort sur datan.fr, pas chez nous.** Le cache de
  sortie de CodeIgniter est aveugle à la chaîne de requête : la page d'iframe y
  est servie octet pour octet identique avec et sans le paramètre (même MD5,
  46 172 o). Le code du legacy implémente pourtant bien la 1re personne, et
  c'est elle que nous rendons — divergence volontaire avec ce que le site
  *affiche*, parité avec ce qu'il *dit*.
- **Corrections typographiques assumées, à ne pas « ré-aligner » sur le site.**
  Virgule décimale (`0,86` contre `0.86` — cohésion des pages de groupe
  comprise depuis le 4 août), « Assemblée nationale » en minuscule là où deux
  titres du legacy capitalisent, accords et coquilles des mentions légales,
  « à la Réunion » contre « à la La Réunion », élision « d'Ajaccio », et
  l'espace avant le point de « … le 23 janvier 2025 . » sur la fiche d'un
  ancien député.
  Chacune porte son commentaire dans le gabarit : sans lui, le prochain lecteur
  y voit une erreur de portage et « répare » dans le mauvais sens.
- **Deux phrases à trou du legacy ne sont pas reproduites.**
  `/partis-politiques/<abrev>`, pour un parti sans député, publie
  « Actuellement, député est rattaché » — un `if` refermé trop tôt ;
  `/groupes/legislature-17/ni` publie « le groupe NI est le e plus gros
  groupe », les non-inscrits n'ayant pas de rang. Dans les deux cas on retombe
  sur la phrase que le site sert quand sa donnée est là. Commenté sur place.
- **Des assets manquent sur datan.fr, pas chez nous.** Deux logos de partis
  (REZRE, DEBOU), deux vignettes d'articles (posts 7 et 9, dont l'`image_nom`
  est pourtant identique des deux côtés) et deux portraits de députés inactifs
  (Brigitte Barèges, Pauline Levasseur — avec les 404 correspondants dans les
  journaux du legacy) s'affichent chez nous et pas là-bas. Ne pas prendre ces
  écarts pour une régression : c'est le site de référence qui est incomplet.
- **Les cartes en vis-à-vis des statistiques ne montrent pas toutes le premier
  du classement.** Quatre blocs sur cinq présentent le **dernier** à gauche
  (« Le plus divisé », « Vote le moins », « Le moins de cadres », « Le moins
  représentatif ») ; seul l'âge met le premier. Le sens appartient à l'appel, pas
  au gabarit — ne pas uniformiser. De même, cinq des neuf libellés du menu
  latéral diffèrent du titre de la page qu'ils ouvrent (« La proximité au
  groupe » pour « La proximité des députés à leur groupe ») : d'où un tableau
  `MENU` distinct des titres.
- **Les non-inscrits comptent en participation de groupe, pas en cohésion.** La
  page de participation les garde dans ses cartes — ils y arrivent derniers à
  75 % — quand celle de cohésion les écarte. Les exclure des deux faisait
  remonter GDR à leur place. Incohérent, mais c'est la règle du site.
- **Un groupe rebaptisé en cours de législature dédouble ses coalitions — et
  le recollage se borne à UDR→UDDPLR.** Sans recollage, la coalition
  principale d'EPR comptait 777 scrutins au lieu de 1 183. Le site ne recolle
  qu'**une** filiation (`clean_libelleAbrev()`, daily.php:4580) : depuis le
  30 juillet, `GroupeController::SIGLES_CANONIQUES` fait de même. Ne pas
  regénéraliser à `FamilleGroupe` : SOC et SOC-A restent distincts en 16e,
  leurs coalitions se scindent au 19/10/2023 avec le badge SOC sur les lignes
  d'avant — vérifié, c'est ce qu'affiche datan.fr. Quant au 1 184e scrutin du
  site, il est élucidé et **irréductible** : `coalitions_groupes` est une
  table jamais vidée — `INSERT … ON DUPLICATE KEY UPDATE` sans suppression, un
  scrutin qui cesse de qualifier y laisse sa ligne pour toujours. Le compte du
  site est une accumulation monotone, nécessairement supérieure ou égale à un
  recalcul propre : 1 183 est le chiffre juste.
- **API Platform expose en écriture par défaut.** Toute nouvelle entité
  `#[ApiResource]` doit déclarer `operations: [new Get(), new GetCollection()]` ;
  les deux règles d'`access_control` rattrapent (en 500) celle qui l'oublierait.
  À revérifier à chaque entité exposée — le défaut est ouvert, pas fermé.
- **L'encart « dernier vote important » de la fiche est codé en dur.** Comme le
  legacy (`Deputes::index`), `DeputeController::voteFeature` pointe un scrutin
  figé — 17e législature, n° 3684 (suspension de la réforme des retraites) — et le
  texte de `_vote_feature.html.twig` est éditorial. Quand la rédaction met un autre
  vote en avant, ces deux points changent ensemble ; seule la position du député y
  est dynamique.
- **Le backup public est un instantané daté.** Toute parité — URL, effectifs,
  comptes, décryptages — se vérifie contre le site **vivant** : `deputes_last` y
  portait un slug que datan.fr ne sert plus (Roubache). Deux écarts persistants
  en relèvent, et **aucun n'est un défaut de portage** :
  - **Christine Le Nabour** est EPR chez nous (son seul rattachement ouvert,
    donc le calcul est juste) et HOR sur le site. D'où EPR 91 contre 90, HOR 35
    contre 36, 45 femmes EPR contre 44, ESBMP 114 contre 113 — visible sur la
    féminisation (HOR passe du 6e au 8e rang), le simulateur de coalition, la
    représentativité, et le classement de loyauté qui compte 577 lignes contre
    ses 576 (elle est la ligne en plus). Une moisson Tricoteuses le résoudra ;
    les deux côtés totalisent bien 577.
  - ~~Deux décryptages de juillet 2026 manquants~~ **résorbé, vérifié le
    4 août** : notre table est alignée à l'unité sur le backup du 15/07
    (250 = 250, acétamipride et aide à mourir compris, zéro écart dans les
    deux sens). Le rejeu contre la vraie base au déploiement (§2) reste dû :
    la rédaction a pu publier depuis le 15/07.
- **Saint-Barthélemy / Saint-Martin interchangeables** dans la source
  électorale (977/978), sans moyen de trancher : l'import émet un `[WARNING]`
  nommant les deux communes — chiffres à vérifier avant publication.
- **592 lignes de résultats électoraux écartées** (communes fusionnées depuis
  le scrutin) : chiffre à surveiller — s'il gonfle, c'est `app:import:communes`
  qui a régressé, pas la source.
- **L'historique mensuel de proximité ne pondère pas par le nombre de
  scrutins** : un mois à un seul scrutin vaut 0 ou 100 % (septembre 2025).
  Parité assumée avec datan.fr ; la correction serait un seuil, donc une
  divergence délibérée à consigner. Ne pas confondre avec les trous des
  courbes (`spanGaps: false`), eux volontaires.
- **Cadence des mandats en organe** : `fonction_commission` (COMPER) se
  recharge par `app:import:commissions`, **hors sync**, quand les délégations
  (`app:import:organes`) y sont — quirk assumé ; si l'écran « Postes
  Assemblée » dérive entre ses deux moitiés, c'est là.
- **Jamais d'auto-publication IA.** Le brouillon de décryptage et les résumés
  d'amendements proposent, la rédaction dispose — le décryptage est la seule
  donnée que Datan produit.
- **Un import complet est mort une fois sans message** (22 juillet), signature
  du `cache:clear` concurrent de CLAUDE.md, non reproduit depuis. À rouvrir
  s'il récidive hors de cette circonstance.
