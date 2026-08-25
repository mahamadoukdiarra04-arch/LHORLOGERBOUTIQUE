# Plan de tests et recette

## 1. Jeu de données de test isolé

Utiliser une base MySQL de test distincte. Créer :

- Caisse livreur : ouverture 100 000 FCFA ;
- Orange Money : ouverture 50 000 FCFA ;
- Banque : ouverture 500 000 FCFA ;
- Nocturne Chrono : stock 10, prix 52 000 FCFA, coût historique 30 000 FCFA ;
- Azur Squelette : stock 10, prix 62 000 FCFA, coût historique 35 000 FCFA ;
- une même référence `HOR-TEST-COMPTA-001` avec deux lignes `orders` : 1 Nocturne et 1 Azur, total 114 000 FCFA.

Paiement de livraison : 44 000 FCFA en Caisse et 70 000 FCFA sur Orange Money.

Ventilation attendue au plus fort reste :

| Compte | Nocturne | Azur | Total |
|---|---:|---:|---:|
| Caisse | 20 070 | 23 930 | 44 000 |
| Orange Money | 31 930 | 38 070 | 70 000 |
| Total | 52 000 | 62 000 | 114 000 |

Après une charge Meta directe de 5 000 FCFA sur Nocturne et une charge boutique de 10 000 FCFA :

```text
CA montres = 114 000
CMV = 65 000
marge brute = 49 000
charges directes = 5 000
contribution = 44 000
charges boutique = 10 000
résultat boutique = 34 000 FCFA
trésorerie totale = 650 000 + 114 000 − 15 000 = 749 000 FCFA
```

Les mêmes valeurs doivent apparaître dans Journal, Comptes, TED, Stock, Analyse et Dashboard après bascule comptable.

## 2. Migration et initialisation

- installation neuve depuis `schema.sql` ;
- migration d’une base existante sans supprimer commandes, réassorts, `ad_costs`, utilisateurs ou suivi closeuse ;
- second passage sans catégorie dupliquée ni erreur de colonne/index ;
- aucun compte, solde ou paiement de démonstration créé ;
- comptes inactifs et catégories système correctement protégés ;
- anciennes commandes `Livrée` sans écritures comptables restent inchangées ;
- vérification des clés uniques de sortie source.

## 3. Livraison normale

1. ouvrir une des deux lignes de la référence de test ;
2. demander `Livrée` ;
3. vérifier que le formulaire charge les deux lignes et 114 000 FCFA ;
4. saisir les deux paiements ;
5. confirmer.

Attendus : toutes les lignes sont `Livrée`, deux opérations dans un groupe, allocations exactes, stock Nocturne = 9 / Azur = 9, une sortie par ligne, snapshots 30 000 / 35 000, Caisse = 144 000 et Orange Money = 120 000.

## 4. Idempotence et concurrence

- double-clic sur la confirmation ;
- rejeu du POST avec la même clé ;
- deux requêtes concurrentes avec deux clés sur la même référence ;
- actualisation de la page de résultat.

Attendus : un seul groupe effectif, deux opérations de paiement seulement, deux sorties seulement. La requête concurrente perdante retourne une erreur compréhensible sans écriture partielle.

## 5. Reliquat et exception

- tenter 113 999 puis 114 001 FCFA en mode normal ;
- livrer avec 44 000 FCFA et motif ;
- tenter la même exception sans motif ;
- régulariser 30 000, puis 40 000.

Attendus : les montants erronés sont rejetés. L’exception conserve un reliquat 70 000, puis 40 000, puis se ferme à zéro. Les allocations ne dépassent jamais le total de chaque ligne.

## 6. Vente directe et stock

- créer une vente directe multi-montres avec deux comptes ;
- modifier le total envoyé par navigateur ;
- double-soumettre ;
- tester une quantité supérieure au stock ;
- tester sans déduction avec motif.

Attendus : le serveur recalcule, le stock sort une fois, la vente sans stock crée une alerte et le stock ne change pas. Une vente directe rattachée ultérieurement à une référence ne crée pas de second encaissement.

## 7. Décaissements, Meta et réassort

- charge directe Meta avec produit ;
- charge Boutique sans produit ;
- charge directe sans produit et charge Boutique avec produit ;
- paiement de réassort rattaché au produit ;
- solde négatif sans puis avec consentement.

Attendus : portées incompatibles rejetées ; la dépense directe influence seulement Nocturne ; le réassort baisse la trésorerie sans réduire immédiatement le TED ; l’exception négative est auditée.

## 8. Transfert, remboursement et contrepassation

- transfert de 10 000 FCFA Caisse → Banque, avec 500 FCFA de frais ;
- source = destination ;
- remboursement partiel d’une ligne avec et sans retour stock ;
- contrepassation d’un décaissement, puis seconde tentative.

Attendus : transfert principal neutre au global, frais seuls réduisent trésorerie/résultat. Un remboursement ne dépasse pas le net encaissé. Les deux mouvements de contrepassation restent visibles et s’annulent exactement ; la deuxième contrepassation est refusée.

## 9. Coût historique et `ad_costs`

- livrer une montre au coût test, puis enregistrer un réassort plus cher ;
- vérifier le TED historique ;
- enregistrer une dépense Meta comptable et une ligne `ad_costs` historique non liée ;
- effectuer une reprise Meta explicite deux fois avec la même clé.

Attendus : la marge passée ne bouge pas. La ligne `ad_costs` non liée n’est pas comptée dans le TED réel. La reprise idempotente ne crée qu’un décaissement.

## 10. Journal, droits et pièces

- combiner tous les filtres, pagination et recherche avec accents ;
- vérifier écran mobile à 390×844, tablette et desktop ;
- POST sans CSRF, sans session, avec `product_id`/`account_id` modifié ;
- connexion closeuse aux routes comptables ;
- upload exécutable renommé, MIME faux, fichier > limite ;
- téléchargement de pièce sans droit.

Attendus : aucun débordement ni total divergent ; closeuse redirigée ; attaques rejetées ; pièces privées ; aucun chemin serveur ou erreur SQL visible.

## 11. Critères finaux

- aucun écart d’un FCFA entre opérations et allocations ;
- même période = mêmes chiffres dans toutes les pages ;
- aucun double encaissement/sortie sous rejeu ;
- aucune opération confirmée modifiable ou supprimable ;
- aucune route alternative vers `Livrée` sans transaction comptable ;
- temps de réponse acceptable avec Journal paginé et requêtes vérifiées par `EXPLAIN`.
