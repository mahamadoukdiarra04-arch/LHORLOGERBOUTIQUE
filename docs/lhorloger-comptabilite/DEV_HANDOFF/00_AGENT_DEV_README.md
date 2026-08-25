# Passation développement — Comptabilité & trésorerie L’Horloger

## Mission

Implémenter un module de trésorerie et de rentabilité dans l’administration PHP/MySQL de L’Horloger. Il doit connecter les encaissements réels, les dépenses, les comptes, les réassorts, les commandes à paiement à la réception et le classement des montres.

Le travail ne consiste pas à intégrer le prototype KAMNI ni à créer une application séparée. Les documents voisins s’inspirent de ses principes, mais sont adaptés au schéma et aux règles de L’Horloger.

## Ordre de lecture obligatoire

1. `01_SPEC_FONCTIONNELLE.md`
2. `02_REGLES_METIER_ET_CALCULS.md`
3. `03_MODELE_DONNEES.md`
4. `04_INTEGRATION_PHP_ET_PARCOURS.md`
5. `05_CONTRATS_ACTIONS_ET_VALIDATIONS.md`
6. `06_PLAN_IMPLEMENTATION.md`
7. `07_TESTS_ET_RECETTE.md`
8. `08_DECISIONS_ET_ECARTS.md`
9. `../BRIEF_UX_UI_COMPTABILITE_TRESORERIE.md`

## Contexte technique confirmé

- PHP 8.3, MySQL/InnoDB, HTML/CSS et JavaScript léger ;
- point commun : `php-site/app/bootstrap.php` ;
- pages actuelles : `php-site/public/admin/index.php`, `orders.php`, `stock.php`, `analysis.php` et `closer.php` ;
- schéma : `php-site/database/schema.sql` ;
- rôles existants : `manager` et `closer` dans `admin_users` ;
- devise : FCFA en entiers ; fuseau à fixer explicitement à `Africa/Bamako` dans PHP et MySQL ;
- une référence `orders.order_ref` représente une vente et peut regrouper plusieurs lignes produits ;
- stock actuel : `stock_movements`, avec coût moyen issu des réassorts ;
- les commandes en production sont des données réelles : aucun test destructif ni seed financier fictif n’est autorisé.

## Résultat attendu

- navigation **Comptabilité** réservée aux gestionnaires ;
- quatre vues : Vue d’ensemble, Journal, TED — Rentabilité, Comptes & catégories ;
- encaissement obligatoire ou exception motivée lors du passage d’une référence à **Livrée** ;
- ventes directes, décaissements, transferts, régularisations et contrepassations ;
- comptes réels, pièces facultatives, journal auditable et alertes ;
- lien exact entre réassort, coût historique, sortie de stock et rentabilité produit ;
- aucune double comptabilisation entre commandes livrées, `ad_costs`, Journal, Dashboard et Analyse produits.

## Invariants non négociables

1. Une référence de commande est encaissée une seule fois, même si elle contient plusieurs lignes `orders`.
2. Livraison, encaissement et sortie de stock sont atomiques dans une transaction MySQL.
3. Les montants sont des entiers FCFA ; aucun `FLOAT` pour un calcul financier.
4. Une opération confirmée est immuable ; sa correction est une contrepassation liée.
5. Le stock et le coût sont figés au moment de la vente, jamais recalculés depuis le coût moyen ultérieur.
6. Un transfert ne modifie ni le CA ni le résultat boutique.
7. Le Dashboard et l’Analyse lisent les mêmes agrégats comptables après activation ; ils ne réadditionnent jamais les commandes livrées.
8. `ad_costs` historique n’est pas doublé comme dépense comptable sans rattachement explicite.
9. Les gestions de compte et les opérations financières appartiennent au rôle `manager`, jamais à la closeuse.
10. Les contrôles importants restent côté serveur, même sans JavaScript.

## Définition de terminé

Le module est terminé lorsque les migrations sont idempotentes, les scénarios de recette passent, les soldes se réconcilient avec le Journal, les montants sont cohérents sur mobile et desktop, et aucun chemin ancien ne permet de livrer/encaisser deux fois une référence.
