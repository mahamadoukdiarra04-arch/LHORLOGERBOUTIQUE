# Plan d’implémentation recommandé

## Principe

Livrer par phases vérifiables. Ne pas basculer les chiffres réels du Dashboard ou les sorties de stock avant validation du grand livre et des transactions de livraison. Toute phase est testée sur une base MySQL dédiée, jamais sur les commandes de production.

## Phase 0 — Préparer

- créer une branche dédiée ;
- sauvegarder la base et documenter la procédure de restauration ;
- inventorier les `orders` par `order_ref`, `stock_movements` et `ad_costs` ;
- capturer les parcours actuels Commandes, Stock, closeuse et Dashboard ;
- vérifier le comportement avec et sans JavaScript.

**Sortie :** point de départ reproductible, aucune donnée de test en production.

## Phase 1 — Schéma et références

- écrire la migration comptable datée et idempotente ;
- créer comptes, catégories, groupes, opérations, allocations, ventes directes, exceptions, rapprochements, pièces et audit ;
- ajouter les clés de source/snapshots à `stock_movements` ;
- ajouter les index nécessaires à `orders.order_ref` ;
- semer uniquement les catégories système, jamais les comptes ni soldes.

**Sortie :** migration rejouable sur base vide/existante sans duplication.

## Phase 2 — Noyau serveur

- services Comptes, Catégories, Opérations, Allocations, Rapports ;
- calcul exact de répartition au plus fort reste ;
- calcul des soldes, flux, reliquat et état de paiement par `order_ref` ;
- brouillon, confirmation et contrepassation ;
- tests unitaires des sommes, arrondis et coûts historiques.

**Sortie :** agrégats cohérents sans page UI.

## Phase 3 — Stock transactionnel et livraison

- extraire la sortie de stock dans un service qui réutilise la transaction active ;
- remplacer la sortie idempotente basée sur une note par une clé source ;
- construire `accounting_confirm_delivery()` sur une référence complète ;
- intercepter le statut Livrée dans `admin/orders.php` ;
- ajouter le repli `accounting-delivery.php` sans JavaScript ;
- couvrir paiements multiples, exception et rejeu.

**Sortie :** aucune voie directe vers Livrée, une référence ne crée jamais deux encaissements/sorties.

## Phase 4 — Opérations de trésorerie

- régularisation de commande livrée ;
- vente directe et contrôle stock ;
- décaissement Produit/Boutique ;
- transfert et frais ;
- remboursement, rapprochement et pièces ;
- reprise explicite des coûts Meta historiques si validée.

**Sortie :** chaque action laisse une trace groupée, auditée et réconciliable.

## Phase 5 — Interfaces

- Vue d’ensemble, Journal, TED, Comptes & catégories ;
- formulaires accessibles, dialogues/replis sans JavaScript ;
- états vides, initialisation, erreur, confirmation, non affecté ;
- validation à 390 px, tablette et desktop.

**Sortie :** aucune carte/table/formulaire ne déborde ; les tâches critiques se font sur mobile.

## Phase 6 — Connexions aux pages existantes

- liens Comptabilité dans la navigation ;
- bloc Paiement dans le détail Commandes ;
- bloc Rentabilité réalisée dans Stock ;
- Dashboard/Analyse basculés sur les agrégats comptables après validation ;
- distinction explicite entre réalisé et prévisionnel ;
- alertes croisées vers le Journal.

**Sortie :** mêmes chiffres pour une même période dans toutes les pages.

## Phase 7 — Durcissement et déploiement

- audit CSRF, rôles, requêtes, uploads, erreurs et concurrence ;
- vérifier les index avec `EXPLAIN` sur un Journal représentatif ;
- exécuter recette complète et répétition de migration sur copie ;
- prévoir retour arrière et maintien du mode « Comptabilité à initialiser » jusqu’à saisie des soldes d’ouverture réels.

**Sortie :** recette signée, sauvegarde vérifiée, aucune alerte bloquante.

## Livrables attendus

- code PHP/CSS/JS intégré ;
- migration + schéma neuf ;
- tests ou scripts reproductibles ;
- procédure de mise en service ;
- liste des fichiers modifiés, résultats de test et captures desktop/mobile ;
- note de toute dérogation à ce handoff avec impact sur les calculs.
