# L’Horloger — version PHP / MySQL

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
