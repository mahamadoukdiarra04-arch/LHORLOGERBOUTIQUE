# Contrats d’actions et validations serveur

## 1. Règles communes

Toute action financière exige :

- session authentifiée avec `require_manager()` ;
- méthode `POST` et jeton CSRF ;
- clé UUID `idempotency_key` pour toute confirmation financière ;
- identifiants vérifiés en base, comptes/catégories actifs ;
- transaction MySQL lorsque plusieurs tables sont touchées ;
- recalcul des totaux côté serveur ;
- audit, message puis Post/Redirect/Get.

Le client ne décide jamais du coût, du total d’une vente, de l’état de paiement, de la catégorie de traitement ou de la disponibilité de stock.

## 2. Livraison et encaissement

### Entrée

```text
action=confirm_delivery
csrf
idempotency_key
order_id                # sert uniquement à retrouver order_ref côté serveur
effective_at
exception_mode=0|1
exception_reason
payments[0][account_id]
payments[0][amount_fcfa]
payments[0][payment_reference]
payments[n]...
```

### Validations

- la ligne existe ; le serveur charge toutes les lignes de la même `order_ref` ;
- référence non annulée, non déjà livrée sauf rejeu de la même clé ;
- toutes les lignes ont produit, quantité et prix valides ;
- comptes actifs ; montants strictement positifs ; les lignes d’un même compte sont fusionnées serveur ou rejetées ;
- mode normal : paiements = reliquat exact ;
- mode exception : paiements ≤ reliquat et motif obligatoire si reliquat restant ;
- stock disponible sur tous les produits ; aucune sortie de stock source déjà existante ;
- la date est valide et raisonnable dans le fuseau Bamako.

### Sortie

Groupe, opérations, allocations, snapshots de coût, sorties de stock, mise à jour de toutes les lignes de la référence, exception éventuelle et audit sont créés dans une transaction unique. Une requête rejouée avec la même clé retourne le groupe initial.

## 3. Régularisation d’une référence livrée

```text
action=collect_order_balance
csrf
idempotency_key
order_ref
effective_at
payments[...]
```

La référence doit être `Livrée` avec un reliquat strictement positif. La somme est > 0 et ≤ reliquat. Les allocations ne couvrent que la part non encore encaissée. L’exception ouverte se ferme automatiquement lorsque le reliquat est nul.

## 4. Vente directe

```text
action=create_direct_sale
csrf
idempotency_key
effective_at
customer_name
customer_phone
channel
deduct_stock=0|1
stock_skip_reason
items[0][product_id]
items[0][quantity]
items[0][unit_price_fcfa]
items[0][discount_fcfa]
items[n]...
payments[0][account_id]
payments[0][amount_fcfa]
payments[n]...
note
```

Le serveur exige au moins une montre et un paiement, verrouille les produits, recalcule chaque ligne et vérifie que la somme des paiements est le total réel. Sans déduction de stock, `stock_skip_reason` est obligatoire. Vente, lignes, groupe, opérations, allocations et sorties sont atomiques.

## 5. Décaissement

```text
action=create_disbursement
csrf
idempotency_key
effective_at
account_id
amount_fcfa
category_id
scope=product|shop
product_id
label
counterparty
payment_reference
note
allow_negative_balance=0|1
negative_balance_acknowledgement
attachment
status=draft|confirmed
```

Validations : montant positif, compte/catégorie actifs, libellé obligatoire, portée compatible avec le traitement. Une charge directe impose un produit ; une charge Boutique le refuse. Un solde négatif exige la case de consentement et une note. Le contenu de la pièce est validé côté serveur indépendamment du type/nom annoncé.

Pour un achat de stock ou transit, la catégorie `inventory` doit être associée au réassort si celui-ci existe. Son impact TED immédiat reste nul.

## 6. Transfert

```text
action=create_transfer
csrf
idempotency_key
effective_at
source_account_id
destination_account_id
amount_fcfa
fee_amount_fcfa
payment_reference
note
allow_negative_balance=0|1
```

Les deux comptes doivent être actifs et distincts. Le transfert principal ne possède pas d’allocation TED. Les frais créent une seconde opération de charge Boutique sur le compte source. Le contrôle de solde porte sur transfert + frais.

## 7. Remboursement

```text
action=create_refund
csrf
idempotency_key
source_kind=order|direct_sale
source_reference
effective_at
account_id
amount_fcfa
reason
lines[0][source_line_id]
lines[0][amount_fcfa]
lines[0][quantity]
lines[0][return_to_stock]=0|1
lines[n]...
```

Le remboursement est limité au net encaissé de la ligne/référence. La somme des lignes doit être égale au montant ; les allocations inversent le CA et, seulement si le retour physique est confirmé, le coût/stock correspondant. Il est interdit de sur-rembourser ou de recréer un retour déjà appliqué.

## 8. Contrepassation et rapprochement

```text
action=reverse_operation
csrf
idempotency_key
operation_id
effective_at
reason
```

L’originale est confirmée et non déjà contrepassée. Le motif est obligatoire. La nouvelle opération inverse comptes, nature et allocations ; l’originale reste visible.

```text
action=create_reconciliation
csrf
account_id
reconciled_at
statement_balance_fcfa
note
attachment
```

Le système calcule le solde à la date, enregistre l’écart et ne crée aucun ajustement automatique.

## 9. Comptes et catégories

- création de compte : code unique, type autorisé, solde/date d’ouverture, auteur ;
- modification : libellé, description et activité uniquement après première utilisation ;
- catégorie système : libellé, ordre et activité modifiables ; code et traitement en lecture seule ;
- toute création, désactivation ou modification est auditée ;
- les comptes/catégories utilisés ne sont jamais supprimés.

## 10. Messages métier attendus

Les erreurs sont précises sans exposer de SQL :

- « La référence HOR-… présente un reliquat de 55 000 FCFA ; vous avez réparti 50 000 FCFA. »
- « Nocturne Chrono ne dispose que de 1 unité pour une sortie de 2. »
- « Cette opération a déjà été confirmée. Rechargez le Journal. »
- « Le compte Orange Money deviendrait négatif. Ajoutez une note et confirmez cette exception. »
