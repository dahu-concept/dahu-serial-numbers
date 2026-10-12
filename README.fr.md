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

Onze statuts, et une matrice qui dit quels mouvements sont permis :

```
reçu ─→ en stock ─→ affecté ─→ expédié ─→ retour attendu ─┬→ revenu conforme ─┬→ en réparation ─→ remplacé
            ↑          │          │                       │                   ├→ remise en stock ─→ en stock
            └──────────┴──────────┘                       └→ revenu non conforme
                                                                              └→ détruit
```

Tout changement de statut passe par un seul contrôle. Il n'y a pas de seconde
porte, et aucun drapeau nulle part ne désactive le contrôle.

Trois des formes méritent une explication :

- **Affecté → en stock** est une désaffectation explicite, journalisée comme
  n'importe quel autre mouvement. Sans elle, libérer une unité veut dire
  modifier une ligne à la main.
- **Expédié → en stock** existe pour un cas réel : une commande repassée de
  terminée à en traitement. Forcer un détour par *affecté* ferait dire à
  l'historique quelque chose qui n'a pas eu lieu.
- **Revenu non conforme** ne mène nulle part. C'est un état d'alerte : que
  faire d'une unité revenue autre que ce qui était attendu est une décision, et
  l'extension refuse de la deviner.

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
    function ( $tracking, $order, $carrier, $station_id ) {

        // Il y en a déjà un — étiquette imprimée plus tôt, numéro saisi au
        // poste ? On n'y touche pas.
        if ( '' !== $tracking ) {
            return $tracking;
        }

        $etiquette = mon_transporteur_api_creer_etiquette( array(
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
    4
);

// 2. Construire un lien que quelqu'un peut suivre. Rien n'est deviné par
//    défaut : il n'y a aucune liste d'URL de transporteurs dans cette
//    extension, et un lien deviné qui tombe en 404 est pire que pas de lien.
add_filter(
    'dahu_serial_numbers_tracking_url',
    function ( $url, $tracking, $carrier, $order ) {
        return 'https://mon-transporteur.example/suivi/' . rawurlencode( $tracking );
    },
    10,
    4
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

Neuf routes sous `dahu-serial-numbers/v1`, employées par l'écran d'atelier.
Toutes sauf la connexion exigent un jeton délivré à un poste atelier.

| | |
|---|---|
| `POST /auth/login` | identifiant et mot de passe en entrée, jeton en sortie |
| `POST /auth/logout` | retire le jeton |
| `GET /orders` | la file de préparation, ou une recherche avec `q` |
| `GET /orders/{id}` | une commande, ses lignes et leurs numéros de série |
| `POST /orders/{id}/serials` | affecter un numéro de série à une ligne |
| `DELETE /orders/{id}/serials/{serial}` | le retirer |
| `POST /orders/{id}/shipment` | fermer le colis |
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
| `dahu_serial_numbers_auth_provider` | remplacer le backend d'authentification |
| `dahu_serial_numbers_token_ttl` | la durée d'une session |
| `dahu_serial_numbers_auth_max_attempts` | les échecs avant verrouillage |
| `dahu_serial_numbers_auth_lockout_window` | la durée du verrou |
| `dahu_serial_numbers_before_shipment` | créer une étiquette, rendre un numéro de suivi |
| `dahu_serial_numbers_block_on_label_failure` | refuser le colis si le transporteur échoue, au lieu de l'enregistrer |
| `dahu_serial_numbers_shipment_status` | où va la commande une fois expédiée |
| `dahu_serial_numbers_tracking_url` | construire un lien de suivi |

**Actions**

| | |
|---|---|
| `dahu_serial_numbers_created` | une unité a été enregistrée |
| `dahu_serial_numbers_transitioned` | une unité a bougé |
| `dahu_serial_numbers_shipped` | un colis a été fermé |
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
