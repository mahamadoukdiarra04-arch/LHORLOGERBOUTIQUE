# Intégration PHP et parcours

## 1. Architecture recommandée

Ne pas placer de calcul financier complexe directement dans les pages `public/admin/*.php`. Ajouter des services ciblés dans `php-site/app/` :

| Fichier | Responsabilité |
|---|---|
| `accounting.php` | constantes, disponibilité du module, helpers communs |
| `accounting_accounts.php` | comptes, soldes et rapprochements |
| `accounting_categories.php` | catégories et traitements fixes |
| `accounting_operations.php` | création, confirmation, Journal, contrepassation |
| `accounting_allocations.php` | répartition exacte et contrôles de somme |
| `accounting_sales.php` | ventes directes, paiements et remboursements |
| `accounting_delivery.php` | livraison atomique d’une `order_ref` |
| `accounting_reports.php` | Vue d’ensemble, TED, rentabilité produit |
| `accounting_attachments.php` | pièces privées et téléchargement authentifié |

Inclure ces fichiers depuis `app/bootstrap.php` après la connexion et les helpers d’authentification. Éviter les dépendances circulaires.

## 2. Pages gestionnaires

| Page | Rôle |
|---|---|
| `public/admin/accounting.php` | Vue d’ensemble et actions principales |
| `public/admin/accounting-journal.php` | Journal, filtres, pagination, détail |
| `public/admin/accounting-ted.php` | TED, comparaison et drill-down montre |
| `public/admin/accounting-settings.php` | comptes, catégories, initialisation, rapprochements |
| `public/admin/accounting-operation.php` | détail, pièce, contrepassation |
| `public/admin/accounting-delivery.php` | parcours sans JavaScript Livraison + encaissement |
| `public/admin/accounting-download.php` | téléchargement de justificatif autorisé |

Toutes demandent `require_manager()`. Ajouter le lien Comptabilité dans `app/templates/admin-header.php`. L’espace `/closer/` ne change pas : il n’expose aucune donnée de compte ni action financière.

## 3. Réutilisation UI

Réutiliser `app.css`, `brand.css` et `admin-overrides.css`. Créer `public/assets/css/accounting.css` seulement pour les composants propres : cartes de comptes, cascade TED, carte mobile Journal, lignes de ventilation et feuille d’action mobile.

Les modales HTML peuvent utiliser `<dialog>` et JavaScript léger. Chaque action doit avoir une page de repli complète sans JavaScript. Le navigateur fournit seulement aperçu de total, ajout de lignes de paiement et confirmation visuelle ; le serveur recalcule tout.

## 4. Refactorisation de la livraison

La logique actuelle dans `public/admin/orders.php` fait directement : verrouillage d’une ligne, contrôle de stock, sortie, changement de statut. Elle doit être remplacée pour le statut `Livrée`.

Règle cible :

- le changement vers `Livrée` depuis la liste ouvre le formulaire `accounting-delivery.php?ref=...` ;
- le serveur dérive toujours la référence depuis `order_id`, puis charge toutes ses lignes ;
- seule `accounting_confirm_delivery(PDO $pdo, string $orderRef, ...)` est autorisée à marquer une référence livrée ;
- les autres statuts utilisent le parcours actuel, avec les contrôles canal Meta/Réachat déjà existants ;
- aucune route ne peut mettre une seule ligne de la référence à `Livrée` sans les autres.

### Ordre transactionnel

1. commencer la transaction ;
2. rechercher le groupe via clé d’idempotence ; le retourner en cas de rejeu ;
3. verrouiller toutes les lignes `orders` de la référence et leurs produits ;
4. vérifier statut, total, reliquat, comptes et exception éventuelle ;
5. créer groupe, opérations de paiement et allocations ;
6. figer le coût et créer une sortie unique par `orders.id` ;
7. mettre toutes les lignes à `Livrée`, `stock_processed=1`, `delivered_at` ;
8. créer/mettre à jour l’exception de paiement, l’audit et l’événement admin ;
9. valider puis Post/Redirect/Get.

Au moindre échec, le rollback annule aussi bien encaissement que stock et statut.

## 5. Stock et coûts existants

Extraire de `stock.php` une fonction transactionnelle de disponibilité et de sortie qui accepte le `PDO` déjà ouvert. Ne pas appeler une transaction imbriquée.

Le coût d’une sortie est déterminé à partir du coût moyen disponible au moment de la sortie en V1, puis copié dans `stock_movements.unit_cost_snapshot_fcfa` et les allocations. L’enregistrement `Réassort` reste le lieu de saisie achat/transit.

Le bouton **Coût ads** évolue progressivement : il peut ouvrir un décaissement prérempli `Publicité Meta + produit + période`. Les lignes historiques `ad_costs` restent séparées tant qu’aucun rapprochement n’est validé.

## 6. Dashboard, Analyse et Stock

Après configuration des comptes :

- `admin/index.php` remplace le CA réel, marge et trésorerie par `accounting_reports.php` ;
- les indicateurs prévisionnels restent nommés « Prévision » ;
- `analysis.php` remplace la marge estimée après ads par les allocations/CMV réels, et conserve les données historiques avec badge incomplet ;
- `stock.php` ajoute un bloc **Rentabilité réalisée** et un lien « Voir dans le TED » ;
- le même filtre de période doit donner les mêmes totaux partout.

Tant que l’assistant de comptes n’est pas terminé, afficher « Comptabilité à initialiser » au lieu de valeurs de trésorerie fausses.

## 7. Performance et sécurité

- pagination serveur du Journal, filtres GET normalisés ;
- agrégats SQL par besoin, pas de requête N+1 par carte ;
- indexer compte/statut/date, catégorie/date, produit/date et groupe ;
- CSRF, `POST`, `require_manager()`, requêtes préparées et échappement HTML ;
- pièces JPEG/PNG/WebP/PDF validées via MIME/signature, ≤ 10 Mo par défaut, stockées hors `public/` ;
- messages métier sans trace SQL, chemins ni identifiants techniques.
