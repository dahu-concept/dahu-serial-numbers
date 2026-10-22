# Dahu Serial Numbers

La traçabilité à l'unité pour WooCommerce. Une ligne par unité physique
expédiée, un cycle de vie qui refuse les mouvements qui n'ont pas de sens, un
historique en ajout seul, et un écran d'atelier où le numéro est scanné au
poste plutôt que ressaisi de mémoire après coup.

Sous licence MIT. Nécessite WordPress 6.0, PHP 7.4 et WooCommerce 7.0. Aucun
service externe, aucun compte nulle part, aucun dossier `vendor`.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## À quoi ça sert

Deux questions, posées à des mois d'intervalle.

Un client appelle pour un moteur en panne. Lequel a-t-il reçu, de quel lot
venait-il, et combien d'autres unités de ce lot sont parties ? Sans numéro de
série, la réponse est la date de la commande et une supposition.

Un lot se révèle défectueux. Quels clients en ont une, et lesquelles sont
encore en rayon ? Sans numéro de série, la réponse est « tous ceux qui ont
acheté ce produit » — ce qui fait à la fois trop de monde à écrire et pas les
bonnes personnes.

Cette extension répond aux deux, parce que chaque unité a une ligne et chaque
ligne a un historique.

## Le cycle de vie

Treize statuts, et une matrice qui dit quels mouvements sont permis. À l'aller :

```
reçu ─→ en stock ─→ affecté ─→ expédié ─→ retour attendu
            ↑          │          │
            └──────────┴──────────┘
```

et au retour, où les deux sortes de retour n'ont délibérément pas la même
forme :

```
retour attendu ─┬→ revenu conforme ──────┬→ en réparation
                │                        ├→ remise en stock ─→ en stock
                │                        └→ détruit
                │
                └→ revenu non conforme ──┬→ en réparation
                                         ├→ remplacé
                                         └→ détruit

en réparation ─┬→ réparé ─┬→ affecté   (il repart sur la commande d'où il vient)
               │          ├→ remise en stock ─→ en stock
               │          ├→ en réparation   (il a raté le contrôle final)
               │          └→ détruit
               ├→ remplacé
               └→ détruit
```

et un statut qui n'est ni à l'aller ni au retour — une unité que la boutique a
toujours et a décidé de ne pas compter :

```
en stock ─→ tenu hors compte ─→ en stock
```

Tout changement de statut passe par un seul contrôle. Il n'y a pas de seconde
porte, et aucun drapeau nulle part ne désactive le contrôle.

Quatre des formes méritent une explication :

- **Affecté → en stock** est une désaffectation explicite, journalisée comme
  n'importe quel autre mouvement. Sans elle, libérer une unité veut dire
  modifier une ligne à la main.
- **Expédié → en stock** existe pour un cas réel : une commande repassée de
  terminée à en traitement. Forcer un détour par *affecté* ferait dire à
  l'historique quelque chose qui n'a pas eu lieu.
- **Revenu non conforme** ne retourne pas sur l'étagère. Il peut partir en
  réparation, être remplacé ou détruit — trois décisions, toutes
  enregistrables — mais *remise en stock* n'en fait pas partie : une unité
  revenue autre que ce qui était décrit passe d'abord entre les mains de
  quelqu'un.

  Jusqu'à la 1.2.0, ce statut ne menait nulle part du tout, au motif que « que
  faire d'une telle unité est une décision, pas une transition ». C'était faux,
  et il vaut mieux le dire que de le corriger en silence : une transition est
  précisément *comment une décision s'enregistre*. L'état terminal ne faisait
  pas décider la boutique, il rendait sa décision inenregistrable — et comme
  une unité dans un envoi ouvert est refusée par tous les autres envois, elle
  ne pouvait même pas être enregistrée comme repartie. Seul du SQL manuel la
  libérait.

- **Réparé** est neuf en 1.2.0. Avant lui, la seule sortie d'une réparation
  était *remplacé*, qui dit que le client a reçu une autre unité ; il n'y avait
  aucun moyen d'enregistrer l'issue la plus courante de toutes, à savoir que
  cette unité-ci a été réparée. Elle peut ensuite retourner en stock, revenir
  sur l'établi si elle a raté le contrôle final, ou être détruite.

  Depuis *réparé*, elle peut repartir sur la commande d'où elle vient,
  retourner en stock, revenir sur l'établi, ou être détruite.

  Cette première arête a été écrite, mesurée, retirée, puis remise — et la
  mesure vaut d'être connue si vous expédiez du matériel sérialisé. `ship()`
  ramassait toutes les unités affectées d'une commande **sans aucun contrôle de
  quantité**, donc une ligne de quantité un dont le remplacement était déjà
  parti pouvait expédier une seconde unité, encore sur l'établi, et
  l'enregistrer comme étant chez le client. La correction était dans
  l'expédition, pas dans le cycle de vie : **aucune ligne n'expédie plus
  d'unités qu'elle n'en a commandées**, et le colis entier est refusé plutôt
  que rogné, parce que l'extension ne choisit pas laquelle de deux unités était
  dans le carton.

- **Déplacer une unité à la main.** L'écran des numéros de série propose, sur
  une unité, uniquement les mouvements que la matrice autorise depuis où elle
  est. Chaque mouvement entre dans l'historique de l'unité, avec qui l'a fait.
  Il n'existe aucun moyen d'écrire un statut que le cycle de vie n'autorise
  pas — ni depuis cet écran, ni depuis les routes REST, ni ailleurs.

Une unité remise en stock est une unité libre : la commande, la ligne de
commande, le ticket SAV et le pointeur de remplacement sont tous effacés. Rien
n'est perdu — ce qui est effacé est écrit dans la ligne de journal.

Une boutique qui a une étape à elle — une quarantaine, un second contrôle —
élargit la matrice par un filtre plutôt que d'écrire des statuts dans le dos de
l'extension. Les cibles qui ne sont pas des statuts connus sont ignorées : une
faute de frappe dans un filtre ne doit pas ouvrir une porte.

## L'écran d'atelier

Une page à part, sur `/packing/`, désactivée tant que vous ne l'activez pas.

Ce n'est pas un écran d'administration, parce que la personne qui s'en sert
n'est pas administratrice et n'a pas à l'être. Emballer, c'est quelqu'un devant
un établi avec une douchette. Lui donner un compte WordPress pour scanner des
numéros de série, c'est lui donner wp-admin — et le lui retirer quand elle part,
c'est y penser.

L'écran se connecte donc avec des **postes atelier** : un identifiant par
poste, pas un par personne. Un poste est une tablette sur un établi, employée
par qui emballe. L'historique enregistre quel poste a fait quoi, qui est la
question qu'on se pose vraiment : pas *qui* a emballé ce colis, mais *où*.

L'écran :

- liste les commandes à préparer, et combien d'unités il manque à chacune ;
- prend un scan à la fois et le vérifie auprès de la boutique à l'instant — une
  unité déjà sur une autre commande, ou qui n'est pas en stock, est refusée
  avec une phrase qui dit laquelle ;
- refuse à une ligne plus d'unités qu'elle n'en a commandé ;
- ne ferme pas un colis tant qu'une unité n'est pas comptée ;
- enregistre le transporteur et le numéro de suivi sur la commande au départ.

Une douchette est un clavier, et beaucoup de fournisseurs encodent une URL sur
l'étiquette plutôt que le numéro. L'adresse entière atterrit dans le champ, et
l'extension la réduit au numéro de série — la refuser serait exact et inutile,
puisque la personne au poste n'a aucun autre moyen de saisir ce numéro.

## Les postes atelier

Créés sur **Numéros de série → Postes atelier**. Un poste a un identifiant, un
mot de passe d'au moins douze caractères, et un nom qui s'affiche sur l'écran et
dans l'historique.

Désactiver un poste, ou remplacer son mot de passe, le déconnecte partout où il
était connecté — tout de suite, pas quand sa session finira par expirer. Un
poste n'est jamais supprimé : l'historique pointe sur des identifiants de poste,
et une ligne qui répond « où ce colis a-t-il été emballé » par un identifiant
disparu ne répond rien.

## L'expédition, et ce qui n'y est volontairement pas

L'extension ferme un colis : chaque unité qu'il contient est enregistrée comme
partie, le transporteur et le numéro de suivi vont sur la commande là où le SAV
les cherchera, et la commande avance. Ça fonctionne sur n'importe quelle
boutique, sans compte nulle part.

Elle ne parle **pas** à un transporteur. Aucune création d'étiquette, aucun
identifiant d'API, aucune correspondance de types de colis — tout cela demande
un compte, un contrat et une configuration propres à une boutique, et livrer une
interface sans rien derrière serait livrer une fonctionnalité qui ne fait rien
tant que quelqu'un n'a pas écrit une classe PHP.

### Greffer son transporteur

> **Mise à jour depuis la 1.0.0 ?** `dahu_serial_numbers_before_shipment` a
> gagné un paramètre en **deuxième** position — `$consignment_id` — donc
> `$order` passe en troisième. Un écouteur déclaré avec `10, 4` et l'ancien ordre
> reçoit un entier là où il attend la commande, et échoue ; l'extension
> l'attrape, ferme le colis quand même, et la seule trace est une note sur la
> commande. Passez la signature à `( $tracking, $consignment_id, $order,
> $carrier, $station_id )` et déclarez-la avec `10, 5`.

Une intégration transporteur est une extension à elle seule — trente lignes,
deux filtres, et rien à changer ici. Quel que soit le contrat : Chronopost,
Colissimo, Sendcloud, un transitaire, un coursier qui prend un CSV une fois par
jour.

```php
<?php
/**
 * Plugin Name: Mon transporteur pour Dahu Serial Numbers
 */

// 1. Créer l'étiquette quand le poste ferme le colis, et rendre le numéro de
//    suivi. Ce que vous rendez est enregistré sur la commande et écrit dans
//    l'historique de chaque unité.
add_filter(
    'dahu_serial_numbers_before_shipment',
    function ( $tracking, $consignment_id, $order, $carrier, $station_id ) {

        // Il y en a déjà un — étiquette imprimée plus tôt, numéro saisi au
        // poste ? On n'y touche pas.
        if ( '' !== $tracking ) {
            return $tracking;
        }

        // Le colis est déjà ouvert et contient déjà ses unités : vous pouvez
        // dire au transporteur exactement ce qu'il y a dans le carton — les
        // mettre sur la déclaration en douane, le bordereau, où il faut.
        $envoi  = Dahu_Serial_Numbers_Consignments::get( $consignment_id );
        $series = $envoi ? $envoi['serials'] : array();

        $etiquette = mon_transporteur_api_creer_etiquette( array(
            'contenu'  => implode( ', ', $series ),
            'poids'    => $order->get_meta( '_cart_weight' ),
            'nom'      => $order->get_formatted_shipping_full_name(),
            'adresse'  => $order->get_shipping_address_1(),
            'cp'       => $order->get_shipping_postcode(),
            'ville'    => $order->get_shipping_city(),
            'pays'     => $order->get_shipping_country(),
        ) );

        // Rangez le PDF où vous voulez — cette extension ne veut pas le savoir.
        $order->update_meta_data( '_mon_transporteur_etiquette_url', $etiquette['pdf_url'] );

        return $etiquette['numero_de_suivi'];
    },
    10,
    5
);

// 2. Construire un lien que quelqu'un peut suivre. Rien n'est deviné par
//    défaut : il n'y a aucune liste d'URL de transporteurs dans cette
//    extension, et un lien deviné qui tombe en 404 est pire que pas de lien.
add_filter(
    'dahu_serial_numbers_tracking_url',
    function ( $url, $tracking, $carrier, $order, $consignment_id ) {
        // $order peut valoir null quand le lien est construit à partir d'un
        // envoi seul.
        return 'https://mon-transporteur.example/suivi/' . rawurlencode( $tracking );
    },
    10,
    5
);
```

**Ce qui se passe quand l'API du transporteur est en panne**, parce qu'un matin
elle le sera. Levez une exception, et le colis est quand même enregistré comme
expédié : chaque unité passe en *expédié*, la commande avance, et la panne est
écrite dans la note de commande, dans l'historique de chaque unité, et sur
l'écran d'atelier pour que la personne au poste la voie avant d'attaquer le
colis suivant.

C'est un choix délibéré, et le raisonnement est que les deux choses en jeu ne
valent pas la même chose. Un numéro de suivi perdu se rattrape — quelqu'un
l'ajoute à la commande cet après-midi. Savoir **quelles unités sont parties
dans quel carton** ne se rattrape pas : le carton est scotché et sur un camion,
et la seule personne qui le savait est au poste suivant. Laisser l'exception
remonter voudrait dire une erreur 500 sur l'écran d'atelier, un colis fermé, et
rien de tracé — l'extension échouant à la seule chose qu'elle sait faire parce
que le serveur de quelqu'un d'autre n'a pas répondu.

Une boutique où rien ne peut partir sans une vraie étiquette le dit, et alors
rien ne bouge du tout :

```php
add_filter( 'dahu_serial_numbers_block_on_label_failure', '__return_true' );
```

## Les envois (registre d'envois)

Un **envoi** (*consignment*) est l'ensemble des unités parties — ou revenues —
ensemble, sous une même référence : `C-2026-00042` en sortant, `R-2026-00007` en
entrant. Il répond à la question que rien d'autre ne pouvait traiter : *quelles
unités étaient dans le colis portant ce numéro de suivi ?* Avant, le
transporteur et le numéro de suivi étaient des métas de commande, un de chaque
par commande, et le lien entre un numéro et ses unités devait se deviner dans
l'historique.

Fermer un colis ouvre désormais un envoi, y place les unités, puis le ferme. Les
métas de commande (transporteur, numéro de suivi, référence) sont toujours
écrites, en miroir du dernier colis sortant, pour le thème, l'e-mail ou
l'export qui les lit.

**C'est un livre de comptes, pas un acteur.** Le registre ne déplace aucune
unité d'un statut à l'autre ; c'est `Dahu_Serial_Numbers_Shipment` qui le fait,
et qui se sert du registre pour noter ce qu'il a fait. Toujours pas d'API
transporteur, d'étiquette ni de manifeste : le transporteur est un nom que vous
saisissez, le numéro de suivi une chaîne que vous collez.

### Expéditions partielles

`Dahu_Serial_Numbers_Shipment::ship()` accepte `$args['serials']` pour n'expédier
qu'une partie d'une commande, et `POST /orders/{id}/shipment` accepte le même
argument `serials`. Omis, toute la commande part, comme avant.

Le contrôle de complétude — *chaque unité de la commande a un numéro de série* —
ne s'applique qu'à l'expédition de la commande entière. Il ne s'applique pas à
une expédition partielle : une commande qui part en deux colis a forcément des
unités sans numéro pendant que le premier part, et le refuser reviendrait à
obliger à mentir sur l'autre moitié pour n'en expédier que la moitié. Chaque
numéro nommé doit être affecté à la commande, sinon l'appel est refusé. Par
défaut la commande ne passe à *terminée* que lorsqu'il ne reste rien à envoyer ;
sinon son statut est laissé tel quel.

### L'écran « Consignments »

**Serial numbers → Consignments** dans l'administration. Une seule boîte de
recherche accepte une référence, un numéro de suivi ou un numéro de série. Il est
en lecture seule, sauf un bouton : **annuler un envoi resté ouvert**. C'est la
sortie de secours quand un processus est mort entre le déplacement des unités et
la fermeture du colis, ce qui rendrait sinon les unités incapables de rejoindre
un autre envoi — y compris celui qui enregistre leur retour. Un envoi fermé n'est
jamais annulable : le colis est parti.

### Lire le registre en REST

| | |
|---|---|
| `GET /consignments` | une liste ; **exige au moins un** paramètre parmi `order_id`, `tracking`, `reference`, `serial` |
| `GET /consignments/{reference}` | un envoi et ses unités |

La liste refuse volontairement une requête non cadrée. Le jeton qui garde ces
routes est celui d'un poste d'atelier — une tablette partagée sur un établi, pas
une personne — et une liste sans filtre lui livrerait tout l'historique
d'expédition de la boutique. La préparation a toujours un contexte (une commande,
une étiquette, une unité en main) : rien de légitime n'est perdu.

L'écriture du registre n'est **pas** exposée en REST, délibérément. Une
intégration transporteur tourne dans le même processus PHP et n'a pas besoin de
route, et une route permettrait à un jeton d'établi d'écrire des mouvements
arbitraires.

### Les tables sont un contrat public

`dahu_serial_numbers_consignments` et `dahu_serial_numbers_consignment_serials`
(avec votre préfixe de tables) peuvent être lues par d'autres extensions. Leurs
modifications sont **additives seulement**. La version du contrat est
`Dahu_Serial_Numbers_Consignments::API_VERSION`, lisible sans charger la classe
via l'option `dahu_serial_numbers_consignments_api`, et renvoyée par
`GET /consignments` dans `api_version`. Le schéma de base est en version 5 ; la
dernière version a ajouté `label_error`, une colonne nullable qui porte le message
du transporteur quand une étiquette a été refusée, de sorte que « les colis partis
sans étiquette » est un `WHERE` (`Consignments::query( array( 'label_error' => true ) )`)
et non un `LIKE` sur du texte libre. `API_VERSION` reste à `1` : les quatre ajouts
sont additifs.

### Ajouter un numéro de suivi une fois le colis parti

Quand un transporteur ne répond pas, le colis est clos quand même et la note de
commande dit d'ajouter le numéro de suivi à la main.
`Dahu_Serial_Numbers_Shipment::amend_tracking( $consignment_id, array( 'carrier' => '…', 'tracking' => '…' ) )`
le fait sur un envoi **clos**, et déclenche
`dahu_serial_numbers_consignment_tracking_amended( $id, $consignment, $previous )`.
Elle écrit le transporteur et le suivi, rien d'autre : toute autre clé (`status`,
`serials`, `note`, les mesures…) est refusée par
`dahu_serial_numbers_amend_only_tracking` et rien n'est écrit. Le miroir de métas
de la commande décrit un seul colis, le plus récent envoi sortant clos ; il est
réécrit comme un ensemble quand on corrige celui-là, et laissé tel quel quand on
corrige un plus ancien.

### Deux limites connues

- **Un envoi annulé garde ses unités.** Quelqu'un les a bien mises dans ce
  carton, et le fait fait partie de l'histoire. Tout lecteur doit donc regarder
  `status` avant de compter des départs ; seuls les envois `closed` ont eu lieu.
- **« Déjà dans un autre envoi ouvert » n'est pas opposable sous concurrence.**
  Le contrôle est une lecture suivie d'une insertion. Deux postes qui ferment
  deux colis au même instant peuvent tous deux s'entendre dire que l'unité est
  libre et tous deux l'enregistrer. La matrice de statuts rattrape — `shipped`
  vers `shipped` est refusé, donc le second mouvement échoue et l'unité ressort
  de son envoi — mais la garantie n'est pas celle de la base. Une table
  d'unicité qui comble l'écart est additive et viendra dans une version
  ultérieure.

## Le stock WooCommerce

Une boutique qui sérialise sait exactement combien d'unités elle détient : elle
en tient une ligne par unité. WooCommerce, lui, tient un nombre qui dérive — un
remboursement passé à la main, une commande annulée remise en stock deux fois,
une unité mise au rebut à l'atelier et nulle part ailleurs. Au bout de quelques
mois les deux ne sont plus d'accord, et celui qui a tort est celui sur lequel
les clients achètent.

Interrupteur allumé, le stock d'un produit sérialisé devient :

```
unités libres en stock  −  pièces déjà réservées par une commande
```

Une unité **libre** est une unité *en stock* et sur aucune commande. Une pièce
**réservée** est une pièce que WooCommerce a déjà retirée pour une commande en
préparation — `_reduced_stock` sur la ligne — et à laquelle personne n'a encore
mis de numéro. Les deux moitiés sont nécessaires :

- Sans les réservations, les unités qu'un client a déjà payées sont remises en
  vente : elles sont encore sur l'étagère et encore *en stock* jusqu'à ce que
  l'atelier les scanne.
- En comptant une réservation dont les numéros sont déjà affectés, les mêmes
  pièces sont retirées deux fois : une fois par WooCommerce à la commande, une
  fois ici. Les unités déjà affectées ou expédiées sur une ligne sont donc
  retirées de la réservation de cette ligne, et jamais sous zéro.

Les commandes *en traitement* et *en attente* réservent ; les commandes
terminées non, parce que WooCommerce garde `_reduced_stock` sur leurs lignes
pour les remboursements et que les compter retiendrait du stock pour toujours.
`dahu_serial_numbers_stock_reserving_statuses` change cette liste.

La valeur est **posée**, pas incrémentée. Un incrément doit être juste à chaque
fois pour rester juste ; une valeur absolue est juste dès qu'elle passe.

**Ce qui n'est pas touché**, et nommé sur l'écran « Stock » plutôt que sauté en
silence : les parents variables, dont les unités appartiennent aux variations ;
les produits dont WooCommerce ne gère pas le stock ; les produits non marqués
comme portant des numéros de série. Une unité qui se dit *en stock* en portant
encore une commande n'est pas comptée non plus, et l'écran dit combien il y en
a : quelque chose l'a remise en stock sans passer par le cycle de vie, et une
poussée de stock n'est pas l'endroit où décider de ce que cela voulait dire.

Une **cible négative** — des commandes qui réservent plus de pièces qu'il n'y a
d'unités sur l'étagère — est écrite zéro, et l'écart est nommé. Écrire un stock
négatif laisserait WooCommerce continuer à vendre sur une précommande que la
boutique n'a jamais mise en place.

**Coupée à l'installation**, et l'écran « Stock » se lit dans les deux cas : il
montre chaque produit, ce que WooCommerce dit aujourd'hui et ce qui serait
écrit. La resynchronisation manuelle fonctionne interrupteur coupé, derrière une
case de confirmation, et refuse de tourner sur un récapitulatif qui a bougé
depuis — on lit un tableau, on va déjeuner, on revient et on clique, et entre
les deux une commande est arrivée.

Une poussée ne bloque jamais ce qui l'a déclenchée. Un produit qui ne peut pas
être enregistré est noté comme refus et l'import, le scan d'emballage ou la
commande d'où il vient continue : un nombre de stock vaut moins que l'opération
qui l'a produit.

## Quand un carton est rangé sous la mauvaise référence

Deux références diffèrent d'un connecteur, d'un standard de frein, ou de rien
qu'un œil repère sur une étiquette ; un carton de quarante part sur l'étagère
sous la mauvaise. À partir de cette minute les deux produits comptent faux, et
la boutique l'apprend quand un client reçoit l'autre pièce.

Le second onglet de l'écran « Stock » re-range les unités. Ce n'est
délibérément pas un changement de statut : l'unité n'a pas bougé, il ne lui est
rien arrivé, et lui donner un événement de cycle de vie mettrait un mensonge au
seul endroit que cette extension existe pour tenir honnête. Ce qui est
enregistré est une correction — une ligne d'historique qui nomme les deux
produits et le motif, qui est obligatoire.

- **Un essai à blanc d'abord**, le même chemin de code que l'exécution, rapporté
  et non écrit, avec le stock des deux produits avant et après.
- **Tout ou rien.** Un seul refus refuse toute la liste, et l'écriture tourne
  dans une transaction : vingt unités déplacées et vingt restées en arrière est
  un état dont personne ne peut rien conclure ensuite.
- **Une empreinte**, pour que ce qui est appliqué soit ce qui a été montré.
- **Annulable**, unité par unité, tant que la passe est la dernière chose qui
  est arrivée à cette unité. Celles qui ont bougé depuis sont nommées et
  laissées tranquilles — les ramener en arrière défer­ait une décision plus
  récente.
- **Coupée à l'installation.** L'essai à blanc fonctionne interrupteur coupé :
  c'est ainsi qu'on décide de l'allumer, en lisant ce qu'il ferait.

Sont refusées : une unité qui n'est pas *en stock*, une unité qui porte encore
une commande (la ligne de commande est l'affirmation la plus forte), une unité
sans produit du tout (il n'y a rien à corriger), et une destination qui est un
parent variable ou qui n'est pas marquée comme portant des numéros de série.

## Quels produits portent un numéro de série

Une case sur le produit, sous **Inventaire**. Sur un produit variable, la cocher
sur le parent couvre chaque variation qui n'a pas d'avis propre.

Une boutique avec quatre cents références sérialisées ne coche pas quatre cents
cases :

```php
add_filter(
    'dahu_serial_numbers_is_serialised',
    function ( $serialise, $product_id ) {
        return $serialise || has_term( 'moteurs', 'product_cat', $product_id );
    },
    10,
    2
);
```

## Lire une date dans un numéro de série

Rien n'est lu par défaut, et c'est la bonne réponse pour une boutique dont les
numéros de série ne codent rien. Une extension qui devinerait remplirait une
colonne de fausses dates qui ont l'air justes.

Certains fournisseurs en codent une. Un schéma est livré pour la forme la plus
répandue — première lettre l'année, deuxième lettre le mois, sur un cycle de
vingt-six ans — et on le branche plutôt que de le supposer :

```php
add_filter(
    'dahu_serial_numbers_decode',
    array( 'Dahu_Serial_Numbers_Format', 'letter_year_month' ),
    10,
    2
);
```

Le cycle compte : `A` vaut 2001, et aussi 2027, et aussi 2053. Le schéma prend
l'occurrence la plus proche de l'année courante, ce qui est juste parce qu'une
unité qu'on reçoit a été fabriquée récemment — et reste juste au fil des années,
ce qu'une table d'années figées ne ferait pas.

Tout autre encodage est une fonction à vous sur le même filtre, qui rend
`array( 'year' => …, 'month' => … )`. Une année hors de 1900-2200 ou un mois
hors de 1-12 est écarté plutôt qu'écrit dans la colonne.

## L'API

Onze routes sous `dahu-serial-numbers/v1`, employées par l'écran d'atelier.
Toutes sauf la connexion exigent un jeton délivré à un poste atelier.

| | |
|---|---|
| `POST /auth/login` | identifiant et mot de passe en entrée, jeton en sortie |
| `POST /auth/logout` | retire le jeton |
| `GET /orders` | la file de préparation, ou une recherche avec `q` |
| `GET /orders/{id}` | une commande, ses lignes et leurs numéros de série |
| `POST /orders/{id}/serials` | affecter un numéro de série à une ligne |
| `DELETE /orders/{id}/serials/{serial}` | le retirer |
| `POST /orders/{id}/shipment` | fermer le colis ; `serials` optionnel n'expédie que ces unités |
| `GET /consignments` | les envois correspondant à `order_id`, `tracking`, `reference` ou `serial` (un au moins est exigé) |
| `GET /consignments/{reference}` | un envoi et ses unités |
| `GET /serials/{serial}` | une unité, avec son historique |
| `POST /serials/{serial}/checks` | enregistrer un pointage physique |

La connexion est la seule route ouverte, et elle doit l'être : un point
d'entrée dont le travail est de délivrer l'identifiant ne peut pas l'exiger. Ce
qui la protège est un verrou — cinq échecs sur un identifiant le ferment dix
minutes, ce qui plafonne les essais et plafonne aussi la quantité de hachage de
mot de passe qu'un appelant non authentifié peut faire faire au serveur.

Ce verrou est par identifiant : il n'empêche donc pas d'essayer un même mot de
passe sur beaucoup d'identifiants. Compter par adresse serait pire qu'inutile
ici : tous les postes d'un atelier partagent une adresse, et une personne qui se
trompe de mot de passe fermerait l'atelier entier.

Les jetons sont des chaînes aléatoires. Ce qui est stocké est leur empreinte
SHA-256, jamais la chaîne elle-même : un vol de la table des jetons ne permet à
personne de se connecter. Il n'y a pas de bibliothèque JWT — un jeton signé sert
quand la signature doit être vérifiée là où la base n'est pas joignable, ce qui
n'est jamais le cas ici.

## Les crochets

**Filtres**

| | |
|---|---|
| `dahu_serial_numbers_transitions` | élargir le cycle de vie |
| `dahu_serial_numbers_columns_cleared` | quelles colonnes un mouvement efface |
| `dahu_serial_numbers_is_serialised` | quels produits portent un numéro de série |
| `dahu_serial_numbers_resolve_product` | rediriger un identifiant de produit |
| `dahu_serial_numbers_normalise` | le dernier mot sur la forme d'un numéro |
| `dahu_serial_numbers_url_parameters` | dans quels paramètres une URL scannée peut le porter |
| `dahu_serial_numbers_decode` | lire une date de fabrication dans un numéro |
| `dahu_serial_numbers_queue_statuses` | quelles commandes la file liste |
| `dahu_serial_numbers_stock_reserving_statuses` | quels statuts de commande retiennent du stock — sans le préfixe `wc-` |
| `dahu_serial_numbers_stock_reserved_map` | le dernier mot sur les pièces que chaque produit doit aux commandes — `( $map, $order_ids )` |
| `dahu_serial_numbers_auth_provider` | remplacer le backend d'authentification |
| `dahu_serial_numbers_token_ttl` | la durée d'une session |
| `dahu_serial_numbers_auth_max_attempts` | les échecs avant verrouillage |
| `dahu_serial_numbers_auth_lockout_window` | la durée du verrou |
| `dahu_serial_numbers_before_shipment` | créer une étiquette, rendre un numéro de suivi — `( $tracking, $consignment_id, $order, $carrier, $station_id )` |
| `dahu_serial_numbers_block_on_label_failure` | refuser le colis si le transporteur échoue, au lieu de l'enregistrer — 4e paramètre `$consignment_id` |
| `dahu_serial_numbers_shipment_status` | où va la commande une fois expédiée — 3e paramètre `$consignment_id` ; le défaut est `completed` seulement quand il ne reste rien à envoyer, chaîne vide (statut inchangé) sinon |
| `dahu_serial_numbers_tracking_url` | construire un lien de suivi — 5e paramètre `$consignment_id` ; `$order` (4e) peut valoir `null` |
| `dahu_serial_numbers_label_failure_note` | reformuler la ligne de la note de commande écrite quand le transporteur a échoué — `( $text, $label_error, $consignment_id )` ; inchangée si personne n'écoute. Pour un appel non idempotent arrivé au délai, où « n'a pas pu créer l'étiquette » peut être faux |
| `dahu_serial_numbers_consignment_row_actions` | ajouter des actions à une ligne de l'écran des envois, comme `plugin_action_links` — `( $actions, $row )` ; les fragments sont filtrés (liens et balises simples) |

**Actions**

| | |
|---|---|
| `dahu_serial_numbers_created` | une unité a été enregistrée |
| `dahu_serial_numbers_transitioned` | une unité a bougé |
| `dahu_serial_numbers_shipped` | un colis a été fermé — 5e paramètre `$consignment_id` |
| `dahu_serial_numbers_consignment_opened` | un envoi a été ouvert |
| `dahu_serial_numbers_consignment_closed` | un envoi a été fermé |
| `dahu_serial_numbers_consignment_cancelled` | un envoi a été annulé |
| `dahu_serial_numbers_consignment_tracking_amended` | un envoi clos a reçu son transporteur ou son suivi après coup — `( $id, $consignment, $previous )` |
| `dahu_serial_numbers_station_credentials_revoked` | un poste a été désactivé, ou son mot de passe changé |

## Les dates

Toutes les dates que cette extension stocke sont en UTC, et sont converties
dans le fuseau de la boutique au moment de l'affichage. Si vous interrogez les
tables vous-même, lisez-les en UTC.

## Désinstallation

Les tables sont conservées, sauf si vous cochez la case sur l'écran de réglages
— et elle est décochée.

Ce ne sont pas des scrupules. Les numéros de série et leur historique sont le
seul document qui dit quelle unité est partie chez quel client, aussi loin que
vous sérialisez, et une demande de garantie arrive deux ans plus tard. Et
désinstaller est le plus souvent une étape d'une réinstallation.

Ce qui part toujours est ce qui ne coûte rien à reconstruire : l'événement
planifié, les compteurs de verrouillage, la version de schéma enregistrée.

## Services externes

Aucun. L'extension n'émet aucune requête sortante, d'aucune sorte.

## Licence

MIT. Hugo Vial-Jaime — Dahu-Concept.
