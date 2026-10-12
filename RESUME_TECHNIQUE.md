# Dahu Serial Numbers — résumé technique

Document interne. L'extension publique est en anglais ; ce fichier est le seul
du dépôt où les origines Annad sont nommées.

Version 1.0.0, portée de `dahu-serials` le 8 octobre 2026.

---

## 1. Le périmètre, et comment il a été choisi

### 1.1 Ce qui a été mesuré avant d'écrire une ligne

`dahu-serials` fait 66 classes et 1 443 Ko. La question n'était pas « que
garder » mais « qu'est-ce qui se détache ». Un graphe de dépendances lu au
**tokenizer** (`_harness/deps.php`, jamais à l'expression régulière) a donné :

| | |
|---|---|
| Fermeture de la v1 telle quelle | **45 classes, 885 Ko** |
| Après coupe de treize arêtes accidentelles | **35 classes, 611 Ko** |
| Tables réellement lues par la v1 | **4 sur 17** |

Les treize arêtes accidentelles valaient 274 Ko : des appels de confort vers
des classes d'un autre domaine (analytique de cohortes, migration produit,
équivalences de catégories) depuis du code de sérialisation.

### 1.2 Le résultat trouvé par le script, et qui change tout

La passe « références par chaîne » de `deps.php` a trouvé ceci dans
`Dahu_Serials_Rest_Controller_V3` :

```php
$v1   = 'Dahu_Serials_Rest_Controller';
$auth = array( $v1, 'check_auth' );
```

`$auth` est ensuite passé en `permission_callback` de **quinze** routes. Le
graphe dur voyait deux références au contrôleur et **aucune arête vers la
couche d'authentification**. Un découpage bâti sur ce chiffre aurait publié
une API sans contrôle d'accès, et aucun test n'aurait échoué.

C'est ce qui a motivé l'extraction n°1 ci-dessous, et c'est pourquoi la passe
par chaîne rapporte **toute** référence, y compris quand une arête dure existe
déjà : le cas qui l'a rendue nécessaire avait précisément deux appels `::`
visibles. Un filtre sur « absent du graphe » l'aurait masqué.

### 1.3 Les quatre extractions prévues, et ce qu'elles sont devenues

Décidées avant l'i18n, parce qu'elles déplacent du code entre fichiers :
traduire d'abord, c'est traduire deux fois. Une seule a été faite comme telle ;
les trois autres ont été réglées autrement, et il vaut mieux l'écrire que de
laisser croire à quatre déplacements.

1. **Faite.** Les trois adaptateurs REST d'authentification quittent le
   contrôleur pour la classe d'authentification. 58 lignes qui déléguaient déjà
   tout. Le `permission_callback` nomme désormais la classe qui autorise, donc
   un lecteur — et un outil — voit ce qui garde les routes.
2. **Sans objet.** `order_is_ready_for_label()` devait aller dans la classe
   d'étiquettes ; il n'y a pas de classe d'étiquettes ici (§2.4). La propriété
   « ce colis est-il complet » est `missing_for_order()`, lue en un seul
   endroit par `Shipment::ship()`.
3. **Réglée par la réécriture.** Les deux lectures partagées V1/V3
   (`order_packing_detail`, `orders_recent`) devaient fusionner. L'API publique
   a été réécrite en neuf routes avec **une seule** forme de réponse de
   commande, donc il n'y a plus deux lectures à rapprocher.
4. **Hors périmètre.** Les six appels à `Cohort_Analytics::flush_cache()`
   devaient devenir une action ; l'analytique de cohortes ne part pas (§1.4).

### 1.4 Ce qui n'est pas porté du tout

Sur les 67 méthodes du manager source, **15** sont ici. Les 52 autres sont du
code réel et qui marche : échange SAV fournisseur, chaîne de remplacement avec
états d'attente et relances, registre de stock avec seuils de réappro,
interrogation HTTP du fabricant (KEYDE), alimentation de l'analytique de
cohortes, résolveur de migration produit.

Elles ne sont pas écartées pour leur complexité. Elles sont écartées parce que
chacune encode une décision que **seule cette boutique** peut prendre — quelles
catégories forment une même famille, quand une unité est « en attente de
remplacement », ce qu'est un point de réappro. Une extension qui livre ces
décisions en dur est une extension fausse partout ailleurs.

Là où le besoin se généralise, il reste un point d'accroche :

| Besoin Annad | Ce que l'extension publique expose |
|---|---|
| Résolveur de migration produit | `dahu_serial_numbers_resolve_product` |
| Table lettre→année du fournisseur | `dahu_serial_numbers_decode` |
| États de cycle de vie supplémentaires | `dahu_serial_numbers_transitions` |
| Produits sérialisés par catégorie | `dahu_serial_numbers_is_serialised` |
| Statuts de commande de la file d'attente | `dahu_serial_numbers_queue_statuses` |
| Colonnes effacées par un mouvement | `dahu_serial_numbers_columns_cleared` |
| Durée de vie des jetons | `dahu_serial_numbers_token_ttl` |
| Backend d'authentification entier | `dahu_serial_numbers_auth_provider` |

---

## 2. Les trois décisions qui changent le code par rapport à la source

### 2.1 L'encodage du fournisseur n'est plus une constante

`dahu-serials` portait `YEAR_MAP` et `MONTH_MAP` en constantes de classe :
première lettre = année (A = 2001, cycle de 26 ans), deuxième lettre = mois
(A–L). Sur la boutique, c'est exact. Dans une extension que quelqu'un d'autre
installe, c'est une supposition sur **son** fournisseur, écrite dans du code
qu'il ne peut pas atteindre — et le premier serial qui n'y répond pas perd sa
date en silence.

L'extension publique ne lit donc **aucune** date par défaut : `decode()` rend
`year => null, month => null`, ce qui est la bonne réponse pour une boutique
dont les serials ne codent rien. Le schéma du fournisseur est livré comme
`Dahu_Serial_Numbers_Format::letter_year_month()`, qu'on branche en une ligne :

```php
add_filter( 'dahu_serial_numbers_decode', array( 'Dahu_Serial_Numbers_Format', 'letter_year_month' ), 10, 2 );
```

La marche du cycle est conservée telle quelle, et elle est meilleure qu'une
table d'années figées : `A` vaut 2001, et aussi 2027, et aussi 2053 ; le schéma
prend l'occurrence la plus proche de l'année courante, donc il reste juste en
2027 sans qu'on y touche.

Un schéma est du code de quelqu'un d'autre : `sanitise_date()` jette une année
hors de 1900–2200 et un mois hors de 1–12 plutôt que de les écrire dans un
`smallint`. Un schéma qui répond n'importe quoi coûte un `null`, pas une erreur
de base au milieu d'un import de neuf cents lignes.

### 2.2 Le quatrième argument de `transition()` n'est pas repris

La source écrit :

```php
public static function transition( $serial, $new_status, array $context = array(), $bypass_matrix_check = false )
```

`$bypass_matrix_check` saute le test de légalité. Il existe pour **un** appelant
et le docblock le dit. Un booléen qui désactive la seule règle qu'une classe
applique est le genre de paramètre qu'un troisième appelant passe à `true`, de
bonne foi, un vendredi à 18 h 40.

Qui a besoin d'un mouvement que la matrice refuse élargit la matrice par
`dahu_serial_numbers_transitions`, en une ligne visible, là où le lecteur
suivant la verra. Et le filtre ne peut pas ouvrir n'importe quoi :
`sanitize_matrix()` écarte les cibles inconnues et les boucles sur soi — une
faute de frappe dans un filtre ne doit pas ouvrir une porte.

### 2.3 Toutes les dates sont en UTC, sans exception

La source écrivait l'échéance d'un jeton avec `gmdate()` et la ligne qui le
créait avec `current_time( 'mysql' )`, **dans le même INSERT**. Toute
comparaison entre les deux était fausse du décalage du site.

Règle de l'extension : **toute colonne datetime contient de l'UTC**, écrite avec
`gmdate()`, relue avec le fuseau nommé à voix haute (`strtotime( $x . ' UTC' )`),
et convertie avec `get_date_from_gmt()` au moment de l'affichage — un seul
endroit, `Dahu_Serial_Numbers_Rest::local()`.

Le fuseau de PHP est un troisième sujet, distinct de celui de la boutique :
WordPress le met à UTC, et n'importe quoi sur le site peut le changer. Le banc
le change pour `Pacific/Kiritimati` (UTC+14) et vérifie qu'un jeton reste
lisible — sans le suffixe, une échéance à douze heures se lit comme passée de
deux heures, et tous les postes connectés sont déconnectés.

### 2.4 Les étiquettes transporteur ne partent pas, et l'expédition si

C'est une décision de périmètre, prise seul et signalée comme telle. Dans
`dahu-serials`, « expédition + étiquettes » veut dire Sendcloud et Colissimo :
un service payant avec identifiants, un contrat, une correspondance de types de
colis propre à une boutique. Livrer l'interface `Shipping_Backend` sans aucun
transporteur dedans, c'est livrer un échafaudage : une fonctionnalité annoncée
dans le readme qui ne fait rien sur une installation neuve tant que personne n'a
écrit une classe PHP.

Ce qui se généralise, et qui est la part qui compte pour la traçabilité, part :
le colis est fermé, chaque unité qu'il contient est enregistrée comme partie, le
transporteur et le numéro de suivi vont sur la commande là où le SAV les
cherchera, la commande avance. Ça marche sur n'importe quelle boutique, sans
compte nulle part.

Une intégration transporteur se branche sur deux filtres —
`dahu_serial_numbers_before_shipment` pour créer l'étiquette et rendre le
numéro, `dahu_serial_numbers_tracking_url` pour construire un lien — sans rien
changer ici. C'est le point de coupe dont on avait parlé, posé comme des
crochets plutôt que comme une interface vide. Un exemple complet de greffon,
sous forme d'extension d'une trentaine de lignes, est dans les deux README.

### 2.5 Ce qui arrive quand le transporteur est en panne

C'est la question que le greffon pose et que la source ne posait pas, parce
qu'elle n'avait qu'un transporteur et qu'il était dans le même code.

Un appel réseau à un tiers échouera un matin. La première écriture laissait
l'exception remonter : erreur 500 sur l'écran d'atelier, colis scotché, **rien
d'enregistré**. L'extension échouait à la seule chose qu'elle sait faire parce
que le serveur de quelqu'un d'autre n'avait pas répondu.

**Décision : le colis est fermé quand même.** Chaque unité passe en `shipped`,
la commande avance, et la panne est écrite à trois endroits — la note de
commande, l'historique de chaque unité (`label_error` dans le contexte), et un
`warning` dans la réponse REST que l'écran d'atelier affiche.

Le raisonnement tient en une phrase : **les deux choses en jeu ne valent pas la
même chose.** Un numéro de suivi perdu se rattrape cet après-midi. Savoir
quelles unités sont parties dans quel carton ne se rattrape pas — le carton est
scotché, sur un camion, et la seule personne qui le savait est au poste suivant.

Une boutique qui préfère refuser pose
`dahu_serial_numbers_block_on_label_failure` à `true` : rien ne bouge alors, ni
les unités ni la commande, et le refus est noté **sur la commande** — pas dans
l'historique des unités, puisqu'aucune unité n'a bougé. Une ligne par unité
dirait qu'elle a bougé alors qu'aucune ne l'a fait.

Six injections couvrent ce chemin.

---

## 3. Les trois défauts trouvés dans la source

### 3.1 L'expiration glissante écrivait en base à chaque appel

`verify_token()` repoussait l'échéance à **chaque** requête. Sur un écran
d'atelier qui sonde, c'est une écriture toutes les quelques secondes, pour une
valeur qui ne change rien avant la fin du poste.

Ici l'échéance ne bouge que quand il reste moins de la moitié de la durée de
vie. La garantie est la même — un poste utilisé n'est jamais déconnecté en
cours de service — pour une écriture toutes les six heures au lieu d'une par
appel.

### 3.2 Rien ne purgeait jamais les jetons expirés

Chaque connexion ajoute une ligne ; seule l'expiration d'un jeton **consulté**
en retirait une. Une boutique à trois postes, c'est quelques lignes par jour —
exactement le genre de croissance que personne ne remarque avant d'en avoir des
années. Un événement quotidien purge (`DAHU_SERIAL_NUMBERS_CRON`), et la table
des jetons a maintenant un index sur `expires_at`, sans quoi ce DELETE sur
plage est un balayage complet.

### 3.3 Désactiver un poste ne coupait pas ses jetons vivants

Le poste ne pouvait plus se connecter, mais celui qui tenait déjà un jeton
restait opérationnel jusqu'à douze heures. Même chose pour un changement de
mot de passe — et la source n'avait d'ailleurs **aucun** moyen de changer un
mot de passe : il fallait créer un second poste et abandonner le premier, ce
qui laisse l'historique pointer sur une ligne que plus personne n'utilise.

Ici le magasin annonce, et la couche d'authentification écoute :

```php
do_action( 'dahu_serial_numbers_station_credentials_revoked', $station_id );
```

Le magasin ne connaît donc pas les jetons — et une boutique qui veut tracer ou
notifier l'événement accroche la même action.

---

## 4. Le banc, et ce qu'il a fallu corriger dans le banc lui-même

**446 assertions sur trois fichiers, 129 injections délibérées.** Chaque
contrôle que le code prétend faire est appelé avec l'entrée qu'il doit refuser,
et chaque refus **asserte son code d'erreur** : la leçon de `dahu-file-cleanup`,
où vingt-et-un refus de chemin passaient ensemble en ne mesurant qu'un seul
garde-fou, parce qu'une même sortie précoce leur répondait à tous.

Les injections ont trouvé **neuf défauts dans mes propres tests**. Les sept qui
méritent d'être écrits :

### 4.1 Le helper de refus décorait son libellé

`sn_refused()` ajoutait ` [refus attendu]` au libellé quand l'appel ne refusait
pas du tout. Le script d'injection apparie les échecs par libellé : dix
injections qui retiraient un garde-fou carrément ont donc été rapportées
« ça a cassé, mais pas sur cette assertion ». Un helper de test ne déplace pas
son libellé.

### 4.2 L'assertion sur l'expiration glissante était creuse

Elle émettait un jeton, appelait `verify_token()`, et vérifiait que l'échéance
n'avait pas changé. Mais un jeton émis dans la même seconde porte déjà
`now + ttl` : une version qui réécrivait l'échéance à chaque appel réécrivait
la chaîne qui s'y trouvait déjà, et le test passait. L'injection qui retirait
la condition entière a laissé le banc vert. L'échéance est maintenant décalée
de soixante secondes avant l'appel.

### 4.3 L'assertion sur l'URL scannée n'atteignait pas le repli qu'elle testait

`wp_parse_url( '?series=ABC', PHP_URL_QUERY )` rend déjà `series=ABC`. Le repli
« chaîne de requête nue » ne sert que pour `series=ABC` **sans** point
d'interrogation, où `wp_parse_url()` rend `null`. Le test a été écrit en
croyant le contraire ; l'injection l'a dit.

### 4.4 Le plafond de pagination n'était pas mesurable depuis l'API

Demander cinq cents commandes sur une boutique de banc qui en a douze rend
douze, avec ou sans plafond. Le plafond est devenu une méthode publique
(`Dahu_Serial_Numbers_Rest::page_size()`) et il est asserté directement : un
garde-fou qui vaut la peine d'exister vaut la peine d'être asserté.

### 4.5 Deux mécanismes de révocation, une seule assertion

Le premier passage attendait le refus `station_disabled` et a reçu
`invalid_token` : passer par le magasin déclenche l'action de révocation, qui
avait déjà supprimé la ligne. Les deux mécanismes sont réels et chacun a
maintenant son assertion — l'action est celle qui s'exécute, et le test dans
`verify_token()` est celui qui rattrape un poste désactivé par autre chose
(une autre extension, une console SQL, un backend qui gère ses propres postes).

### 4.6 Le nom de ligne de commande, et le produit renommé

L'assertion « le nom vient de la ligne, pas du produit actuel » était creuse :
le produit portait encore son nom d'origine, donc lire la ligne et lire le
produit donnaient la même réponse. Le banc renomme maintenant le produit après
avoir créé la commande, puis le renomme.

### 4.7 Le garde-fou que seule une alerte PHP trahit

Le test de longueur dans `letter_year_month()` ne change aucune réponse : sans
lui, `$serial[1]` d'une chaîne d'un caractère vaut `''` en PHP 8, `ord('')`
vaut 0, et le test de bornes rattrape. Ce qu'il empêche est une **alerte PHP**
sur une page qu'on regarde, et un journal rempli sur un import de neuf cents
lignes. Le banc installe donc un gestionnaire d'erreurs et asserte l'absence
d'alerte — sinon ce garde-fou serait une ligne que rien ne mesure.

### 4.8 Les jokers LIKE ne sont pas assertables sur ce banc

Deux assertions ont été écrites sur la recherche : qu'un `%` ou un `_` tapé dans
le champ ne devienne pas un joker. Elles ont échoué, et la cause n'est pas dans
l'extension. `Dahu_Serial_Numbers_Manager::query()` passe par
`$wpdb->esc_like()`, qui est la façon documentée de WordPress et qui est juste
sur MySQL — mais `WP_SQLite_DB::esc_like()` est **délibérément** un no-op sur
l'ancien pilote de `sqlite-database-integration`
(`wp-includes/sqlite/class-wp-sqlite-db.php:191`, commentaire d'origine : « We
only need to overload this function to a no-op for the old driver »), parce que
le nouveau pilote ajoute lui-même `ESCAPE '\\'` à chaque LIKE. Mesuré sur le
banc : `esc_like('%')` rend `%`.

Garder les assertions aurait voulu dire soit un échec permanent — ce qui apprend
à ignorer les échecs — soit un contournement dans l'extension pour satisfaire un
pilote qui n'est pas sa cible. Elles sont remplacées par une note qui mesure et
affiche le fait, et par une assertion sur ce qui **est** vérifiable ici : que la
valeur passe bien en paramètre préparé. À revérifier sur MySQL, §9.

### 4.9 Deux garde-fous qui ne sont pas prouvables, et qui restent

Écrits dans l'en-tête du script d'injection plutôt que laissés en échec
permanent, ce qui apprendrait au lecteur à passer sur les échecs :

- Le test « identifiant ou mot de passe vide » en tête de `authenticate()`. Le
  retirer ne change aucune issue : un identifiant vide ne trouve pas de ligne,
  un mot de passe vide échoue à `wp_check_password()`, même refus, même
  message. C'est un raccourci, pas un garde-fou.
- `revoke_token()` qui répond `true` pour un jeton jamais valide. Il n'y a pas
  de défaut à injecter : la méthode n'a pas d'autre branche.

---

## 5. Ce que regarder les captures a trouvé

Quatre défauts, dont aucun n'était visible pour un contrôle statique. Le fichier
`UI-ADMIN-DAHU.md` répète depuis le début qu'il faut regarder ; c'est la
quatrième fois que ça paie.

### 5.1 Les actions de ligne étaient invisibles, et ma première correction visait la mauvaise propriété

L'écran des postes atelier porte, par ligne, un lien « désactiver » et un champ
« nouveau mot de passe ». WordPress masque `.row-actions` tant que la ligne
n'est pas survolée — correct pour une liste d'articles dont les actions sont
« modifier, corbeille, voir », faux ici : un champ qui disparaît quand le
pointeur quitte la ligne ne peut pas servir.

Première correction : `visibility: visible`. Deuxième capture : rien n'avait
changé. WordPress ne masque pas avec `visibility` mais avec `left: -9999em` sur
un bloc en position relative (`wp-admin/css/list-tables.css:872`). Les actions
étaient rendues, hors de l'écran à gauche — seule la hauteur qu'elles réservent
dans la ligne disait qu'elles existaient. Révélées maintenant comme WordPress
les révèle lui-même : `position: static; left: auto`.

### 5.2 « 5 sur 2 » : rien n'empêchait de sur-affecter une ligne

La capture de l'écran d'atelier affichait **« 5 of 2 »** sur une commande de
deux unités. La donnée était juste ; ce qu'elle révélait ne l'était pas : aucun
contrôle, nulle part, ne refusait le troisième scan sur une ligne qui en
demandait deux.

Le colis contient deux moteurs quoi que dise le registre. Trois numéros de série
sur cette ligne veut donc dire que deux d'entre eux sont ailleurs — et
l'extension venait d'enregistrer qu'ils n'y étaient pas.

Le contrôle a été posé **sur le point d'entrée REST**, pas dans le manager. Le
manager est la primitive, et un import ou une migration a tout à fait le droit
d'écrire l'histoire telle qu'elle a été. Le point d'entrée est celui où une
personne scanne, et une personne qui scanne une troisième unité sur une ligne de
deux s'est trompée.

### 5.3 Des lignes fantômes laissées par les passes d'injection

Trois lignes sur les écrans que rien n'expliquait : un poste atelier sans
identifiant ni nom, un numéro de série nommé `HACKED`, un numéro de série vide.

Elles viennent toutes du même mécanisme. Sous injection, le garde-fou qui refuse
un identifiant vide est retiré, l'appel du banc réussit, et la ligne qui en
résulte porte une valeur que le nettoyage du banc ne sait pas nommer — il
balaie par préfixe. `HACKED` était le témoin d'une assertion sur la liste
blanche des colonnes, écrit dans la colonne `serial` quand la liste blanche
n'est plus là.

Corrigé des deux côtés : le témoin porte maintenant le préfixe du banc comme
tout le reste, et les trois bancs balaient aussi ce qu'ils ne peuvent pas nommer
(identifiant vide, numéro de série vide). **Une passe d'injection qui laisse des
données derrière elle est une passe qui ment à la suivante.**

### 5.4 Le badge « Off » disparaissait

Sur une ligne rayée, le fond du badge était le fond de la ligne : « Off » se
lisait comme une valeur manquante, pas comme un état délibéré. Une bordure
suffit — et la même bordure sur « On », sinon la colonne se décale de deux
pixels d'une ligne à l'autre.

---

## 6. Le contrôle de conformité partagé a dû être corrigé

`_harness/test-conformite.php` refusait tout `permission_callback` en
`__return_true` sauf dans un fichier contenant `hash_equals(` — la règle avait
été écrite pour un webhook de prestataire de paiement. Un point d'entrée de
connexion est un second cas légitime : il délivre l'identifiant qu'il ne peut
donc pas exiger, et sa protection n'est pas une signature mais le verrouillage
après échecs.

Laisser l'échec en place aurait appris à l'ignorer. La règle a donc été élargie
— mais la première version élargie était une recherche de trois mots **n'importe
où dans l'extension**, et trois injections qui retiraient le verrou l'ont
laissée verte : le nom de la constante restait écrit là où on l'appelait. Une
preuve qu'un commentaire suffit à produire n'est pas une preuve.

La version retenue lit les appels à `register_rest_route()` **au tokenizer**,
découpe chacun sur ses parenthèses équilibrées, repère ceux dont la valeur de
`permission_callback` du même appel est `__return_true`, résout la classe de
leur `callback` jusqu'à son fichier, et exige la preuve **dans ce fichier-là**.
Cinq injections la prouvent (`_harness/prove-routes-ouvertes.py`), dont une
qui ouvre la liste des commandes — route dont le `callback` n'est pas la classe
protégée, et que la recherche globale ne pouvait pas rattraper.

Effet de bord documenté : le contrôle ne se lance plus avec `php -n`, qui
désactive tokenizer. Le script le dit et s'arrête au lieu de tomber sur une
erreur fatale au milieu d'un rapport à moitié écrit.

Les onze autres extensions repassent le contrôle inchangées.

---

## 7. Points d'architecture à connaître

### 7.1 Une unité remise en stock est une unité libre

C'est l'invariant que la source avait trouvé en préproduction. Toute transition
**vers** `in_stock` efface `order_id`, `order_item_id`, `service_ticket` et
`replaced_by`. Sinon toute requête filtrant sur `order_id` rend des unités qui
ne sont plus sur cette commande — et ça se lit comme une donnée, pas comme un
reliquat, donc personne n'en doute.

L'effacement s'applique **avant** le contexte de l'appelant, et l'ordre n'est
pas un hasard : un appelant ne peut pas rattacher une commande à une unité
qu'il remet en stock dans le même appel.

`shipped → assigned` est une expédition annulée, pas une désaffectation :
l'unité garde sa commande, seul le pointeur de remplacement part.

Rien n'est perdu : ce qui est effacé est écrit dans la ligne de journal, sous
`cleared_order_id` et compagnie.

### 7.2 Le contexte d'une transition passe par une liste blanche

`CONTEXT_COLUMNS` — six colonnes. Le contexte arrive d'un corps REST, sur une
tablette, dans un atelier. Une boucle sur `array_keys( $context )` laisserait
réécrire `status` et `serial`.

### 7.3 Un seul message pour quatre refus de connexion

Mauvais mot de passe, identifiant inconnu, identifiant vide, mot de passe vide :
même message, même code, même statut 401. Le banc asserte explicitement que les
quatre sont **indiscernables** — c'est l'assertion qui rattraperait quelqu'un
qui « améliore » les messages en les rendant précis, et transforme le point
d'entrée en énumérateur d'identifiants.

Le temps de réponse, lui, trahit l'existence d'un identifiant : un identifiant
inconnu répond avant tout hachage. C'est assumé et documenté dans le fichier.
Un identifiant de poste est imprimé sur une étiquette collée au poste, ce n'est
pas un secret ; et égaliser le coût dépenserait un bcrypt sur un point d'entrée
joignable sans authentification, ce qui offre un moyen de charger le serveur
gratuitement. Ce qui protège ce point d'entrée, c'est le verrou — qui plafonne
aussi la quantité de hachage qu'un attaquant peut faire faire au site.

### 7.4 Le verrou ne protège pas de tout, et le fichier le dit

Cinq échecs sur un identifiant le ferment dix minutes. Le compteur est **par
identifiant**, donc une attaque qui essaie un mot de passe sur beaucoup
d'identifiants n'est pas freinée. Compter par adresse serait pire qu'inutile
ici : tous les postes d'un atelier partagent une adresse, et une personne qui
se trompe de mot de passe fermerait l'atelier entier.

L'assertion qui donne un sens au verrou est celle-ci : pendant le verrou,
**même le bon mot de passe est refusé**. Sans elle, un verrou qui compterait les
échecs mais laisserait passer un bon mot de passe passerait tous les autres
tests du fichier.

### 7.5 Huit routes, et pas une de plus

La source avait deux espaces de noms et dix-huit routes, dont certaines
n'existaient que parce qu'une première version de l'écran avait demandé une
forme légèrement différente. L'une était morte : un point d'entrée `declare`
avait été retiré de l'écran et laissé enregistré — **douze lignes ont été créées
à travers lui** avant que quelqu'un le remarque.

Le banc asserte le compte : huit. Une route que personne n'appelle n'est pas
inoffensive, c'est une surface non authentifiée qui n'a plus de lecteur.

### 7.6 Un écart de produit avertit, il ne refuse pas

À l'affectation, si le serial est enregistré sous un autre produit que celui de
la ligne, la réponse porte un `warning` et l'affectation se fait. L'unité dans
la main de la personne est l'unité qui part dans le carton ; une extension qui
refuserait refuserait un carton juste et un mappage faux.

### 7.7 Le nom d'une ligne de commande vient de la ligne

Jamais du produit actuel. Un produit renommé le mois dernier ne doit pas
réécrire ce qu'une commande de l'an dernier dit avoir été acheté. Le SKU, lui,
vient bien du produit : c'est une référence, pas un libellé historique.

### 7.8 Les clefs de statut sont en anglais, et ce n'est pas cosmétique

Onze chaînes écrites dans une colonne et relues par ce que la boutique
construit par-dessus : un export, un filtre dans l'extension de quelqu'un
d'autre. C'est un contrat, donc jamais traduit — ce sont les **libellés** qui le
sont. La source avait des clefs françaises (`recu`, `en_stock`, `affecte`), ce
qui marche parfaitement sur une boutique et rend l'extension illisible pour
tout le monde.

Les libellés sont construits **à chaque appel** et non tenus dans une constante :
`__()` a besoin du text domain chargé, et une constante est évaluée trop tôt
pour l'avoir. Une extension qui met ses libellés en cache au chargement montre
de l'anglais à une boutique française et personne ne voit pourquoi.

### 7.9 Un poste est un lieu, pas une personne

Une tablette sur un établi, partagée par qui emballe. Ce n'est donc pas un
utilisateur WordPress : un compte porte un rôle, un jeu de capacités et une
session d'administration, et un identifiant qui ouvre une URL publique sur un
atelier n'a rien à porter de tout ça.

Il n'y a **pas** de suppression de poste. L'historique pointe sur des
identifiants de poste, et une ligne qui répond « qui a emballé ce colis » par un
identifiant disparu ne répond rien. Désactiver refuse tout jeton futur en
laissant le passé lisible.

### 7.10 Le mot de passe d'un poste fait douze caractères, pas huit

La source demandait huit. Ce mot de passe ouvre un point d'entrée joignable
sans session WordPress, l'identifiant est imprimé sur une étiquette collée à
l'établi, et le verrou est par identifiant — donc qui connaît l'identifiant
dispose de quelques centaines d'essais par jour, indéfiniment. Huit caractères
choisis par un humain ne tiennent pas.

### 7.11 Décocher retire la clef, il n'y reste pas un « non »

Le drapeau « produit sérialisé » est supprimé plutôt que mis à `no`, pour qu'une
variation sans avis propre reparte sur la réponse de son parent. Stocker `no`
rendrait « pas cochée » et « délibérément non sérialisée » identiques, et elles
ne le sont pas.

### 7.12 Le journal n'est jamais supprimé

Ni par un changement de statut, ni par une désaffectation, ni par la
suppression de la commande à laquelle il se rattache. Un historique troué
répond plus mal que pas d'historique, parce qu'il répond avec aplomb.

### 7.13 L'écran d'atelier imprime son propre document

Pas un gabarit de thème. L'en-tête d'un thème, son menu, son bandeau de
cookies et sa fenêtre d'inscription à la lettre d'information sont autant de
choses devant lesquelles quelqu'un à un établi passe quarante fois par jour — et
une cible tactile qu'une feuille de thème a ramenée à onze pixels est un scan
raté. Cet écran ne doit rien aux visiteurs de la boutique, puisqu'il n'en a pas.

Conséquence : sa feuille de style ne porte **pas** le bloc de jetons
`--dahu-admin-`, qui existe pour que les écrans d'administration de plusieurs
extensions forment une famille. Il n'y a pas de famille ici, c'est la seule page
de son espèce, et les valeurs dont elle a besoin sont d'autres valeurs — une
tablette d'atelier se lit à bout de bras, sous l'éclairage d'un atelier, par
quelqu'un qui tient une pièce dans l'autre main. Rien n'y est tactile sous
52 px.

### 7.14 Le champ de scan est vidé avant la requête, pas après

Une douchette tape le code suivant dès que l'étiquette suivante est sous elle.
Un champ qui tiendrait encore le précédent concaténerait les deux en un numéro
qui n'appartient à personne.

### 7.15 Le jeton de l'écran est en sessionStorage, pas en localStorage

Une tablette d'atelier est partagée. Un jeton qui survit à la fermeture de
l'onglet est un jeton dont hérite la personne suivante. `sessionStorage` survit
à un rechargement, ce dont un atelier a réellement besoin, et pas davantage.
Chaque lecture et chaque écriture est enveloppée : en navigation privée
l'accesseur lève, et l'écran doit continuer de fonctionner le temps de la page.

### 7.16 La désinstallation ne jette rien par défaut

Les quatre tables restent, sauf si la case a été cochée. Ce ne sont pas des
scrupules : la table des serials et son journal sont le seul document qui dit
quelle unité est partie chez quel client, aussi loin que la boutique
sérialise. Et désinstaller est le plus souvent une étape d'une réinstallation.

Ce qui part toujours : l'événement planifié, les compteurs de verrou, la
version de schéma — laisser cette dernière ferait croire à une réinstallation
que les tables sont à jour, et sauterait le `dbDelta` qui les aurait créées.

Les tables sont supprimées dans un ordre choisi : jetons et postes d'abord,
serials et journal en dernier. Si l'opération est interrompue — un dépassement
de délai sur un gros journal — ce qui reste est la donnée de traçabilité, pas
les identifiants.

---

## 8. Modèle de données

Quatre tables, contre dix-sept dans la source.

| Table | Une ligne par |
|---|---|
| `dahu_serial_numbers` | unité physique |
| `dahu_serial_numbers_log` | événement, en ajout seul |
| `dahu_serial_numbers_stations` | poste d'atelier |
| `dahu_serial_numbers_tokens` | jeton délivré à un poste |

La table des serials garde `service_ticket` et `replaced_by` : la matrice de
transition est livrée entière, `in_repair` et `replaced` sont des statuts
qu'un serial peut légalement atteindre, et un statut atteignable a besoin d'un
endroit pour dire comment on y est arrivé. Les écrans qui pilotent ces
transitions sont une version ultérieure ; le vocabulaire, non.

`dbDelta` est pointilleux : deux espaces après `PRIMARY KEY`, un champ par
ligne, le même nom de `KEY` écrit pareil partout. Il compare le **texte** de
l'instruction avec la table vivante, donc une retouche cosmétique ici devient
un `ALTER` sur chaque site.

---

## 9. Ce qui reste à vérifier sur une vraie installation

Le banc tourne sur WordPress + SQLite + WooCommerce. Ce qu'il ne couvre pas :

1. **`dbDelta` sur MySQL réel.** Le banc crée les tables ; il ne les
   fait pas évoluer. Un changement de schéma se vérifie en installant la 1.0.0,
   puis en bumpant `SCHEMA_VERSION`.
2. **Le fuseau sur un vrai site.** Le banc le change par option ; un site a
   aussi un `date_default_timezone_set()` possible depuis un thème.
3. **L'événement quotidien.** Programmé et asserté comme programmé ; son
   exécution réelle par WP-Cron ne l'est pas.
4. **L'écran d'atelier sur une vraie tablette.** Regardé dans un navigateur
   piloté, à 900 px de large. Les tailles tactiles sont posées à 52 px, mais
   c'est sur un établi que ça se juge.
5. **HPOS.** Déclarée compatible, et tout passe par `wc_get_order()` et
   `wc_get_orders()`. Le banc tourne sur le magasin actif de l'installation de
   test ; il faut refaire un passage avec HPOS explicitement activée **et** un
   avec les tables héritées.
6. **L'échappement des jokers LIKE** dans la recherche (§4.8). Juste sur MySQL,
   non assertable sur le banc SQLite. À vérifier en tapant `%` dans le champ de
   recherche de l'écran : la liste doit rester vide, pas rendre toute la table.
7. **La règle de réécriture de `/packing/`.** Vérifiée sur le banc avec des
   permaliens jolis ; à revoir sur une boutique en permaliens simples, où
   l'adresse devient `?dahu_serial_numbers_workshop=1`, et sur une installation
   en sous-répertoire.
8. **Une vraie douchette.** Le format d'URL scannée est testé par les chaînes.
   Une vraie douchette ajoute souvent un suffixe de touche (Entrée, tabulation)
   et parfois un préfixe, qu'il faudra voir sur l'établi.
9. **Le panneau sur l'écran de commande sous HPOS.** La boîte est déclarée sur
   `shop_order` **et** sur `woocommerce_page_wc-orders` ; seul le second a été
   regardé.
10. **Les noms de mois et les formats de date.** Sur les captures en français,
    « Fabriqué » affiche *March 2024* et les dates *October 8, 2026 2:34 pm* :
    ces chaînes-là viennent de `date_i18n()` et de `get_option( 'date_format' )`,
    donc des traductions **du cœur de WordPress**, qui ne sont pas installées
    sur le banc. Sur un vrai site français ce sera « mars 2024 » et le format
    choisi dans les réglages. À confirmer d'un coup d'œil, puisque c'est la
    seule chose que le banc ne peut pas montrer juste.
