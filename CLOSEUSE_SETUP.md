# Espace closeuse

L’espace de travail de la closeuse est disponible à l’URL `/closer/`. Il est séparé de l’administration de gestion (`/admin/`).

Pour créer son accès, ajoutez une entrée avec le rôle `closer` dans `app/config.php` :

```php
'IDENTIFIANT_CLOSEUSE' => [
    'password_hash' => password_hash('MOT_DE_PASSE_A_CHOISIR', PASSWORD_DEFAULT),
    'role' => 'closer',
],
```

Elle pourra alors prendre une commande dans son suivi, noter le résultat de l’appel, confirmer avec le canal Meta ou Réachat, préparer un message WhatsApp pour le livreur et télécharger son bordereau PDF.

Le numéro WhatsApp du livreur se renseigne depuis **Administration → Suivi closeuse**. Utilisez le format international, sans espace (par exemple `223XXXXXXXX`).

L’envoi WhatsApp reste une action explicite de la closeuse : le navigateur ouvre un message prérempli, que la closeuse vérifie puis envoie. Un envoi entièrement automatique demanderait un compte et une API WhatsApp Business.
