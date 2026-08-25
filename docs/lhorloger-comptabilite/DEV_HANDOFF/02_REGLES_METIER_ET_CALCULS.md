# Règles métier et calculs

## 1. Sources de vérité

- les opérations confirmées du Journal sont la source de vérité de la trésorerie ;
- les allocations comptables sont la source du CA et des charges analytiques ;
- `orders` regroupé par `order_ref` est la source des lignes d’une vente web ;
- `direct_sales` et ses lignes sont la source des ventes hors site ;
- `stock_movements` et ses snapshots sont la source des quantités et coûts historiques ;
- les catégories ont un code de traitement immuable ; leur libellé peut évoluer ;
- les mêmes fonctions serveur alimentent Comptabilité, Dashboard, Analyse produits et Stock.

## 2. Montants, date et ordre

- tous les montants FCFA sont positifs dans les lignes et stockés en `BIGINT UNSIGNED` ; le signe vient de la nature/opération ;
- les soldes d’ouverture et écarts sont `BIGINT` signés ;
- aucune formule monétaire n’utilise `FLOAT` ou `DECIMAL` ;
- les quantités analytiques partielles peuvent être `DECIMAL(16,6)` ;
- le fuseau est `Africa/Bamako` ;
- l’ordre de solde stable est `effective_at`, puis `created_at`, puis `id` ;
- une période couvre 00:00:00 à 23:59:59 inclusivement.

## 3. Nature et effet

| Nature | Compte | Trésorerie totale | TED |
|---|---|---:|---:|
| Encaissement | compte + | augmente | selon catégorie/allocation |
| Décaissement | compte − | diminue | selon catégorie/allocation |
| Transfert | source −, destination + | neutre | neutre |
| Ajustement | compte ± | varie | hors résultat, sauf décision explicite |
| Contrepassation | inverse exact | inverse | inverse exact |

Seules les opérations `confirmed` affectent les calculs. Un brouillon est modifiable et nul dans tous les agrégats.

## 4. Traitements analytiques stables

| Code | Effet | Portée |
|---|---|---|
| `product_revenue` | augmente CA montre | produit obligatoire |
| `shop_revenue` | augmente revenu boutique | boutique |
| `product_refund` | diminue CA montre | produit obligatoire |
| `shop_refund` | diminue revenu boutique | boutique |
| `direct_expense` | augmente charge directe | produit obligatoire |
| `common_expense` | augmente charge boutique | boutique |
| `inventory` | aucun effet TED immédiat | réassort/produit |
| `out_of_result` | aucun effet TED | apport, retrait, solde, transfert |

La catégorie conserve son code après utilisation. Une catégorie peut être désactivée, jamais requalifiée rétroactivement.

## 5. Référence de commande et paiements

Pour L’Horloger, le total d’une vente web est :

```text
SUM(orders.quantity × orders.unit_price_fcfa)
WHERE orders.order_ref = référence
```

Le paiement net d’une référence est :

```text
encaissements confirmés liés à order_ref
− remboursements confirmés liés à order_ref
```

État dérivé : `non encaissée`, `partiellement encaissée`, `encaissée`, `sur-encaissée à régulariser`, `remboursée`.

Un paiement réparti entre comptes crée une opération par compte dans le même groupe. Chaque opération ne reçoit que sa fraction d’allocations. La somme des allocations de toutes les opérations du groupe est égale au montant effectivement encaissé, sans création ni perte de FCFA.

## 6. Allocation de ventes multi-produits

Une référence peut avoir plusieurs lignes `orders`. Toute allocation doit couvrir toutes les lignes, jamais seulement la première.

1. calculer le total de chaque ligne (`quantité × prix unitaire`) ;
2. calculer la part non encaissée de chaque ligne ;
3. répartir le paiement au prorata de ces restes avec la méthode du **plus fort reste** ;
4. répartir les parts entre comptes avec la même garantie de somme ;
5. conserver, pour chaque allocation, `order_id`, `product_id`, montant, quantité équivalente et coût historique.

La livraison est gratuite actuellement : aucune ligne de frais n’est créée. Si des frais sont facturés plus tard, ils deviennent une allocation `shop_revenue` séparée, jamais du CA d’une montre.

## 7. Coût historique et stock

Au moment d’une sortie liée à une livraison ou vente directe, figer le coût unitaire issu du réassort :

```text
coût unitaire = (prix d’achat + transit) / quantité du réassort
```

En V1, si aucun suivi de lot/FIFO n’est encore développé, utiliser le coût unitaire moyen disponible au moment de la sortie et le copier dans le mouvement/allocations. Une modification de réassort ultérieure ne doit jamais modifier une marge passée.

Une sortie est unique par ligne `orders.id` ou `direct_sale_items.id`. Le stock est verrouillé avec `SELECT ... FOR UPDATE` avant toute déduction. Une commande livrée est bloquée si le stock est insuffisant ; une vente directe sans déduction exige un motif et crée une alerte.

## 8. Formules TED

Pour la période :

```text
CA net montre = Σ product_revenue − Σ product_refund
CMV = Σ coût historique reconnu − Σ coût des retours physiques
marge brute = CA net montre − CMV
charges directes = Σ direct_expense
contribution montre = marge brute − charges directes

revenus boutique nets = Σ shop_revenue − Σ shop_refund
charges boutique = Σ common_expense
résultat boutique = Σ contributions + revenus boutique nets − charges boutique
```

Les transferts, soldes d’ouverture, apports/retraits et achats de stock `inventory` n’affectent pas le résultat. Le TED est **incomplet** si une opération ayant un impact sur le résultat est non affectée.

## 9. Ad costs historique

`ad_costs` existant est une donnée de coûts Meta par produit/période. À l’activation :

- aucune ligne historique ne devient automatiquement un décaissement ;
- une dépense Meta future crée un décaissement comptable et peut enregistrer/référencer sa période publicitaire ;
- une reprise historique est une action explicite, prévisualisée, idempotente et auditée ;
- le TED ne doit jamais compter la même dépense via `ad_costs` et via une allocation comptable.

## 10. Contrepassation, rapprochement et interdictions

- une opération confirmée est seulement corrigée par une opération inverse liée ;
- une contrepassation ne déclenche pas de retour de stock automatique ; le retour physique est un mouvement distinct ;
- le rapprochement mémorise un solde relevé et l’écart, sans modifier le solde calculé ;
- un ajustement d’écart est une opération séparée, motivée et auditée ;
- aucune ancienne commande livrée ne reçoit un encaissement fictif. Les comptes commencent avec un solde d’ouverture réel.
