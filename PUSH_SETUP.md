# Notifications closeuse — Web Push

## Installation sur le téléphone

1. iPhone iOS 16.4 ou ultérieur : ouvrir `/closer/` dans Safari, Partager → Ajouter à l’écran d’accueil (ouvrir comme app).
2. Ouvrir l’icône L’Horloger, se connecter avec le compte closeuse.
3. Toucher **Activer les notifications**, autoriser, puis **Tester**.
4. Vérifier une réception avec l’application en arrière-plan et le téléphone verrouillé. Les réglages Concentration peuvent retarder/masquer les alertes.

## Serveur et déploiement

PHP 8.2+, extensions curl, openssl, mbstring. Dépendances Composer verrouillées et embarquées dans `vendor/` pour Hostinger sans étape de build. Ne jamais exposer `app/`, `vendor/`, la base ou les clés VAPID. Les clés sont créées une seule fois côté serveur dans `web_push_keys` ; ne pas les régénérer (les abonnements existants en dépendent).

À chaque commande : écriture de la file dans la transaction de commande, envoi après la réponse HTTP quand PHP/FastCGI le permet. Un incident push ne bloque pas la commande. Un verrou empêche deux workers concurrents ; une clé unique évite les doubles insertions par commande/appareil. Les éventuelles relivraisons après une interruption sont regroupées par `tag` côté téléphone. Aucun système réseau ne garantit exactement une réception en toutes circonstances.

**Configurer dans Hostinger une tâche cron toutes les minutes** (adapter ACCOUNT au chemin réel) :

    /usr/bin/php /home/ACCOUNT/domains/lhorloger.shop/public_html/app/push-worker.php

Choisir le binaire PHP 8.2+ du compte Hostinger. Le cron assure les reprises sans visite de l’admin. Sans cron, les envois immédiats fonctionnent, les reprises ne sont déclenchées que par une nouvelle commande ou l’ouverture de l’espace closeuse.

Nouvel essai après 1 min puis délai exponentiel jusqu’à 1 h ; abandon après 8 tentatives ou 24 h. Les abonnements 404/410 sont désactivés. Une récupération complète la file en cas d’insertion manquée, uniquement pour les commandes postérieures à l’abonnement. Les comptes inactifs ou sans rôle closeuse sont exclus à l’envoi.

Les notifications affichent uniquement une alerte générique et ouvrent `/closer/?new_q=REFERENCE`. Le nom et le téléphone restent derrière la connexion. Pas de mise en cache des écrans ni des réponses authentifiées. Le son de fond est géré par iOS, pas personnalisable en son « cash ».

## Recette avant de déclarer la livraison iPhone validée

- Test sur un véritable compte closeuse et un appareil avec permission accordée, app fermée/écran verrouillé.
- Nouvelle commande réelle : une alerte par commande (pas par ligne), clic → filtre de la commande.
- Téléphone déconnecté puis reconnecté : notification dans sa durée de vie, sans promesse de délai absolu.
- Désactivation : aucune nouvelle alerte sur cet appareil ; suspension du compte : aucun nouvel envoi.
- Tester le worker cron, les reprises et vérifier son journal d’exécution.

Contrôles locaux : `php tests/push-test.php`, syntaxe PHP/JS, `composer audit`.
