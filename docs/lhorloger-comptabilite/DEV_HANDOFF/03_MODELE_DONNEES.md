# Modèle de données et migration

## 1. Principes

- MySQL/InnoDB, `utf8mb4`, clés étrangères et index explicites ;
- montants FCFA en `BIGINT UNSIGNED`, sauf soldes/écarts signés ;
- migration versionnée, idempotente et testée sur une copie de la base ;
- les tables actuelles et les commandes existantes sont conservées ;
- aucune comptabilité JSON parallèle ;
- le schéma neuf (`php-site/database/schema.sql`) et la migration portent les mêmes structures.

Nom recommandé de migration : `php-site/database/migrations/20260825_accounting_treasury.sql`.

## 2. Tables nouvelles

### `accounting_accounts`

| Champ | Type | Règle |
|---|---|---|
| id | BIGINT PK | auto-incrément |
| code | VARCHAR(40) UNIQUE | identifiant normalisé, immuable |
| name | VARCHAR(120) | libellé gestionnaire |
| account_type | ENUM | `cash`, `bank`, `mobile_money` |
| opening_balance_fcfa | BIGINT | peut être négatif |
| opening_at | DATETIME | date de départ du compte |
| is_active | TINYINT | désactivation sans suppression |
| description | VARCHAR(500) NULL | |
| created_by_user_id | BIGINT NULL | FK `admin_users.id` |
| timestamps | TIMESTAMP | |

### `accounting_categories`

`code` unique, `name`, `direction` (`receipt`, `disbursement`, `both`), `treatment`, `default_scope` (`product`, `shop`, `unassigned`), `is_system`, `is_active`, `sort_order`, timestamps.

Semer seulement les catégories système : Vente montre, Vente boutique, Remboursement montre, Remboursement boutique, Publicité Meta, Emballage/retouche direct, Logistique directe, Achat de stock, Transit, Loyer, Télécoms, Frais bancaires, Apport/retrait, Ajustement hors résultat. Le code et le traitement ne changent jamais après utilisation.

### `accounting_operation_groups`

Regroupe une action atomique : livraison, régularisation, vente directe, décaissement, transfert, remboursement ou contrepassation.

Champs : `id`, `public_reference` unique, `group_type`, `idempotency_key` unique, `order_ref` nullable, `direct_sale_id` nullable, `created_by_user_id`, `created_at`.

`order_ref` est volontairement textuel ici : la table `orders` de L’Horloger n’a pas de table entête et plusieurs lignes partagent la même référence.

### `accounting_operations`

Une opération décrit l’impact sur un compte.

| Champ | Notes |
|---|---|
| group_id | FK groupe, obligatoire |
| reference | unique, lisible |
| nature | `receipt`, `disbursement`, `transfer`, `adjustment` |
| status | `draft`, `confirmed` |
| account_id | compte principal, FK |
| destination_account_id | transfert uniquement |
| category_id | null pour transfert simple si nécessaire |
| source_type | `order`, `direct_sale`, `manual`, `refund`, `transfer`, `reversal` |
| amount_fcfa | strictement positif |
| effective_at | date métier |
| label, counterparty, payment_reference, note | libellés/sources |
| reversal_of_id | FK unique vers l’originale |
| created_by_user_id, confirmed_by_user_id, confirmed_at | audit |
| timestamps | |

Index : `(account_id, status, effective_at)`, `(category_id, effective_at)`, `(group_id)`, `(source_type, effective_at)`.

### `accounting_allocations`

Ventile une opération sur une montre ou la boutique : `operation_id`, `category_id`, `treatment`, `scope`, `product_id`, `order_id` nullable, `direct_sale_item_id` nullable, `amount_fcfa`, `effect_sign` (`1`/`-1`), `quantity_equivalent`, `unit_cost_snapshot_fcfa`, `cogs_amount_fcfa`, `created_at`.

Contraintes métier : une allocation produit doit avoir `product_id`; une allocation boutique n’en a pas ; la somme des allocations d’une opération externe est égale au montant de l’opération.

### `direct_sales` et `direct_sale_items`

`direct_sales` : numéro unique, client/téléphone/canal facultatifs, sous-total, remise, total, déduction stock, motif si non-déduction, date d’effet, statut, référence éventuellement liée, auteur et timestamps.

`direct_sale_items` : vente parente, produit, nom figé, variante/note de coloris, quantité, prix unitaire, remise, total de ligne, coût unitaire figé et date.

Les totaux sont recalculés serveur. Une vente directe confirmée est liée à un groupe d’opérations, jamais seulement à une note libre.

### `accounting_payment_exceptions`

`order_ref` unique parmi les exceptions ouvertes, `reason`, `status` (`open`, `resolved`, `cancelled`), ouvert/résolu par, dates. L’exception est clôturée automatiquement quand le reliquat est nul.

### `accounting_reconciliations`, `accounting_attachments`, `accounting_audit_log`

- rapprochement : compte, date, solde calculé, solde relevé, écart, note, pièce, auteur ;
- pièce : mouvement/rapprochement parent, nom original, nom stocké aléatoire, MIME contrôlé, taille, chemin privé, auteur ;
- audit : utilisateur, action, entité, identifiant, état avant/après JSON, IP, user agent, date.

## 3. Évolutions des tables existantes

### `orders`

Conserver la structure actuelle. Ne pas la casser en créant une table `order_items` rétroactive. Ajouter au besoin un index `(order_ref, status)` et une date de livraison `delivered_at` nullable. L’état de paiement est calculé depuis les opérations liées à `order_ref`, plutôt que saisi manuellement.

Les actions de livraison doivent mettre à jour toutes les lignes d’une référence dans la même transaction. Une requête sur une ligne est élargie serveur à toutes les lignes de la référence.

### `stock_movements`

Ajouter :

| Colonne | Rôle |
|---|---|
| order_id | ligne `orders.id` source, nullable FK |
| direct_sale_item_id | ligne vente directe source, nullable FK |
| operation_group_id | lien au groupe comptable |
| unit_cost_snapshot_fcfa | coût figé de la sortie |
| sale_unit_price_fcfa | prix de vente historique |
| skip_reason | motif lorsque la déduction est volontairement écartée |

Ajouter une unicité sur `(order_id, movement_type)` et `(direct_sale_item_id, movement_type)` pour les sorties. Les valeurs nulles restent autorisées pour les réassorts et mouvements historiques. La colonne actuelle `unit_cost_fcfa` du réassort reste utile, mais ne suffit pas à réécrire le coût d’une sortie passée.

### `ad_costs`

Ajouter `accounting_operation_id NULL` et éventuellement `actual_paid_at NULL`. Les coûts existants gardent leur historique. Une liaison ne doit être ajoutée que lorsqu’un gestionnaire a validé le décaissement correspondant ; sinon le TED comptable les ignore pour éviter le double compte.

## 4. Données historiques et initialisation

1. créer les comptes avec leurs soldes d’ouverture réels à une date choisie ;
2. ne pas générer d’encaissement à partir des anciennes commandes `Livrée` ;
3. laisser les anciens coûts/ventes sans snapshot comme **historique incomplet** jusqu’à régularisation manuelle ;
4. proposer une reprise optionnelle des anciens `ad_costs` avec aperçu, compte débité, date et clé d’idempotence ;
5. ne jamais insérer de compte, de paiement ou de stock fictif.

## 5. Suppression et intégrité

- comptes et catégories utilisés : désactivation, pas suppression ;
- opérations et ventes confirmées : jamais supprimées physiquement ;
- brouillon supprimable avec audit ;
- `ON DELETE RESTRICT` sur comptes/catégories utilisés ;
- `ON DELETE SET NULL` sur produit exceptionnellement désactivé afin de préserver l’historique ;
- fichier justificatif hors webroot, accès via route authentifiée uniquement.
