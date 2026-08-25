# L’Horloger — Brief UX/UI Comptabilité & trésorerie

**Version :** 1.0 — cadrage pour conception et développement
**Date :** 25 août 2026
**Statut :** base de travail à valider avant implémentation

## 1. Objectif

Ajouter au back-office L’Horloger un espace **Comptabilité** qui répond clairement à quatre questions :

1. Combien d’argent est réellement disponible ?
2. Où est-il : caisse livreur, banque, Orange Money, Moov Money ou autre compte ?
3. Quelles ventes ont été effectivement encaissées et quelles dépenses ont été réellement payées ?
4. Quelle montre est rentable après coût d’achat, transit, publicité et autres charges directes ?

Le module complète Commandes, Stock & coûts, Analyse produits et le travail de la closeuse. Il ne doit pas devenir une comptabilité générale lourde : il s’agit d’une trésorerie traçable et d’une rentabilité de gestion en FCFA.

## 2. Contexte L’Horloger à respecter

- vente de montres à Bamako, majoritairement avec paiement à la réception ;
- la closeuse confirme la commande, puis le livreur reçoit son bordereau WhatsApp/PDF ;
- la livraison est gratuite dans le parcours actuel ;
- une référence `order_ref` peut contenir plusieurs lignes `orders` : c’est une seule vente client et non plusieurs commandes à encaisser ;
- `stock_movements` conserve les réassorts, sorties et ajustements ; le coût d’un réassort contient achat + transit ;
- `ad_costs` contient aujourd’hui des coûts Meta par produit et période ;
- l’administration est en PHP 8 / MySQL, avec des rôles `manager` et `closer` ; seule la gestion accède à la comptabilité ;
- aucun montant fictif ne doit être créé pour initialiser le module ou illustrer les écrans.

## 3. Décision de vocabulaire

| Terme interface | Sens |
|---|---|
| Compte de trésorerie | Emplacement réel de l’argent : caisse, banque, mobile money. |
| Encaissement | Argent effectivement reçu. |
| Décaissement | Argent effectivement payé. |
| Transfert | Mouvement interne entre deux comptes L’Horloger. |
| Charge directe | Dépense attribuée à une montre précise. |
| Charge boutique | Dépense qui concerne l’activité dans son ensemble. |
| TED — Rentabilité | Tableau d’exploitation de gestion : CA encaissé, coûts, charges et résultat. |
| À régulariser | Commande livrée mais non intégralement encaissée. |
| Contrepasser | Annuler l’effet d’un mouvement confirmé en créant son inverse, sans effacer l’historique. |

Préférer « Mouvement » à « écriture comptable ». Les couleurs renforcent l’information, mais ne portent jamais seules un statut.

## 4. Périmètre V1

### Inclus

- comptes configurables : Caisse, Banque, Orange Money, Moov Money et autres portefeuilles ;
- solde d’ouverture saisi par le gestionnaire, sans import automatique d’anciennes commandes ;
- encaissement à la livraison, paiement partiel à régulariser et régularisation ultérieure ;
- encaissement de vente directe, avec une ou plusieurs montres et mise à jour de stock ;
- décaissement : publicité, achat de stock, transit, emballage, livraison, loyer, frais bancaires, apport et autre ;
- affectation obligatoire d’une charge : **une montre** ou **la boutique** ;
- transfert entre comptes, justificatif facultatif et rapprochement manuel ;
- journal, vue de trésorerie, TED et rentabilité réalisée par produit ;
- annulation par contrepassation, audit des actions et alertes actionnables ;
- parcours desktop/tablette/mobile sans défilement horizontal imposé pour les tâches fréquentes.

### Hors périmètre V1

- plan comptable légal, TVA, paie, amortissements et rapprochement bancaire automatique ;
- réservation de stock, gestion fournisseur détaillée ou bons de commande ;
- export Excel/CSV ;
- envoi automatique WhatsApp ou connexion bancaire ;
- ventilation manuelle d’une seule dépense sur plusieurs produits ;
- prévisions avancées : elles pourront s’appuyer sur les données comptables dans une phase ultérieure.

## 5. Architecture d’information

Ajouter **Comptabilité** dans la navigation gestionnaire, entre **Stock & coûts** et **Analyse produits**.

Sous-navigation :

1. **Vue d’ensemble** — soldes, flux, alertes et derniers mouvements ;
2. **Journal** — source de vérité filtrable ;
3. **TED — Rentabilité** — lecture par montre et résultat boutique ;
4. **Comptes & catégories** — paramétrage, soldes d’ouverture, catégories, rapprochements.

Les actions persistantes de la Vue d’ensemble et du Journal sont : **+ Encaissement**, **− Décaissement**, puis **Transférer**.

## 6. Écrans à concevoir

### Vue d’ensemble

En-tête : « Trésorerie L’Horloger », sélecteur de période et rappel que les soldes sont toujours actuels alors que les flux suivent la période choisie.

Cartes principales :

- trésorerie disponible ;
- encaissements de la période ;
- décaissements de la période ;
- flux net de la période.

Puis une carte par compte avec solde actuel, dernier mouvement et variation de période. Le contenu inférieur comprend les derniers mouvements, les charges par catégorie et un bloc **À traiter** : commandes livrées à régulariser, dépenses sans justificatif, opérations non affectées, solde négatif ou rapprochement en retard.

### Journal

Filtres : période, recherche, nature, compte, catégorie, montre, portée, statut, créateur et présence d’un justificatif. Les filtres avancés deviennent un panneau repliable sur mobile.

Version bureau : Date, Référence, Nature, Libellé, Affectation, Compte, Entrée, Sortie, Solde après mouvement, Statut et Action.
Version mobile : cartes avec nature/montant/statut, libellé, date/compte/affectation et bouton Détails.

Le détail montre les liens vers la référence client, la montre, le réassort si pertinent, la pièce, l’auteur et l’historique. Une opération confirmée n’affiche pas de suppression : seulement **Contrepasser**.

### TED — Rentabilité

Le TED distingue impérativement :

```text
CA encaissé des montres
− coût des montres vendues
= marge brute
− charges directes (Meta, contenu, emballage particulier…)
= contribution des montres
+ revenus boutique éventuels
− charges boutique
= résultat boutique
```

Le tableau par montre affiche : unités encaissées, CA net, coût historique, marge brute, charges directes, contribution et taux de contribution. Toute donnée insuffisamment affectée produit un état **Résultat incomplet** avec un lien vers le Journal.

### Comptes & catégories

Premier accès : assistant de création des comptes et de leurs soldes d’ouverture réels. Aucun compte ou solde de démonstration.

Les catégories système ont un traitement fixe, mais un libellé, un ordre et un état actif modifiables. Les comptes utilisés ne sont jamais supprimés : ils sont désactivés.

## 7. Parcours critiques

### A. Livraison et encaissement

Quand un gestionnaire marque une référence comme **Livrée**, l’interface ouvre « Confirmer la livraison et l’encaissement » : total, déjà encaissé, reliquat, une ou plusieurs lignes de paiement, comptes crédités et impact stock. En mode normal, les paiements doivent égaler le reliquat exact. En exception, un motif est obligatoire et la référence reste dans **À régulariser**.

La closeuse ne confirme jamais une livraison ni un encaissement : elle reste sur son suivi commercial.

### B. Dépense Meta d’une montre

Le gestionnaire choisit Décaissement, compte débité, montant, période/facture éventuelle, catégorie **Publicité Meta**, puis la montre. L’écran explique que ce montant réduit la contribution de cette montre. Une charge générale Meta peut être portée Boutique, sans être artificiellement répartie.

### C. Réassort payé

Le paiement d’un réassort diminue immédiatement la trésorerie et se rattache au produit/mouvement de stock. Il ne réduit pas immédiatement le TED : achat et transit sont incorporés au coût historique reconnu lors de la sortie liée à la vente.

### D. Transfert

Un transfert Caisse → Orange Money ou Banque modifie deux comptes, mais ni le CA ni le résultat. Les frais éventuels deviennent un décaissement distinct.

## 8. Mobile, accessibilité et ton

- les actions de saisie utilisent des champs FCFA avec clavier numérique ;
- les modales deviennent une feuille plein écran avec une validation visible ;
- zone tactile minimale de 44 px ;
- montants alignés, séparés en milliers et jamais coupés ;
- libellés persistants, focus visible, messages d’erreur proches des champs ;
- ton simple : « Compte crédité », « Ce que la dépense concerne », « À régulariser » ;
- la direction visuelle reste celle de L’Horloger : ivoire, bleu nuit, accent doré, panneaux sobres et hiérarchie éditoriale.

## 9. Critères de réussite

L’utilisateur doit pouvoir encaisser une livraison simple en moins d’une minute, expliquer chaque solde par les mouvements confirmés, voir l’effet réel d’une dépense Meta sur une montre et détecter immédiatement les montants encore dus. Aucune action comptable confirmée ne peut disparaître, être dupliquée ou modifier silencieusement une vente existante.
