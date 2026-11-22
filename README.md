# L’Horloger — version PHP / MySQL

## Saisie rapide et comptabilité simplifiée

- `Administration > Commandes > Saisie rapide` ou `Comptabilité > Saisie rapide` : sélection par référence complète (25 commandes par page), action commune, puis récapitulatif et validation unique.
- Actions : confirmer, passer en livraison, annuler, livrer et encaisser intégralement, encaisser les soldes ouverts. Date commune, modifiable par commande au récapitulatif. Les canaux existants ne sont jamais remplacés par le canal de secours.
- Le serveur recalcule les montants ; une référence multi-produit n’est comptée qu’une fois. Transaction globale : toute erreur annule le lot entier. Empreinte des commandes pour refuser un récapitulatif périmé, clé d’idempotence persistante pour éviter les doublons. Signaux Meta seulement après validation définitive.
- Les encaissements partiels et multi-comptes restent dans le détail individuel. Une commande livrée ne peut pas être livrée une deuxième fois.
- Dépenses : montant, catégorie, produit si nécessaire, compte et date. Classement et libellé automatiques côté serveur. Notes, références, brouillons et exception de solde négatif dans les options.
- Historique commandes : pagination SQL 10/25/50, recherche et dates conservées à l’ouverture des détails. Actions et filtres séparés dans un panneau responsive. Aucun système de masquage de commandes.
- La migration `20261122_quick_batches.sql` est appliquée automatiquement par `ensure_accounting_schema()` avant toute transaction. Elle ne transforme pas les anciennes écritures.
- Tests : `php tests/accounting_core_test.php`, `php tests/accounting_quick_test.php`. Intégration MySQL isolée : serveur de test loopback `127.0.0.1:3308`, `LHORLOGER_QUICK_TEST=1 php tests/accounting_quick_integration_test.php` (base dédiée `lhorloger_quick_test`, jamais les identifiants de production). Le serveur de prévisualisation CLI peut utiliser `LHORLOGER_LOCAL_CONFIG=tests/fixtures/quick-config.php`; Apache/FPM ignore cet override.

Cette version est destinée à l’offre PHP/HTML de Hostinger. Elle conserve la boutique, le catalogue, les fiches produits, le panier et le parcours de commande. L’administration est une application PHP avec persistance MySQL : commandes, canal d’acquisition, stock, réassorts, coûts de transit, publicité Meta, CAC et rentabilité.

## Mise en ligne Hostinger

1. Dans **Bases de données → Gestion MySQL**, créez une base et un utilisateur.
2. Importez `database/schema.sql` dans phpMyAdmin.
3. Copiez `app/config.example.php` vers `app/config.php`, renseignez la base et remplacez les deux hachages de mots de passe avec `password_hash` (ne publiez jamais ce fichier).
4. Depuis la racine du projet, exécutez `node scripts/prepare-hostinger-php.mjs`. Le dossier généré `php-site/deploy/` contient les images et la structure à envoyer sur Hostinger.
5. Uploadez `deploy/public_html/` dans `public_html`, puis le dossier `deploy/app/` à côté de `public_html`.

La boutique fonctionne à la racine. L’administration est disponible à `/admin/login.php`.

## À noter

Les alertes sonores ne peuvent se déclencher que dans un navigateur ouvert. Pour recevoir une notification lorsqu’aucun navigateur n’est ouvert, il faut compléter cette version avec une notification push (PWA) ou une notification email/WhatsApp déclenchée à la commande.
