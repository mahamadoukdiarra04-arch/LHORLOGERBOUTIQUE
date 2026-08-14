# Mise en ligne Hostinger

Le projet est configuré comme application Node.js avec rendu serveur. Il doit être déployé depuis un dépôt GitHub privé, ou depuis une archive ZIP du projet sans `node_modules`, `dist`, `review`, `tmp` ni fichiers `.env`.

Dans hPanel, choisissez **Deploy Web App**, puis le type **Other** si la détection ne sélectionne pas l’application Node.js automatiquement. Utilisez Node.js 22.x, la commande de build `npm run build` et la commande de démarrage `npm start`. Si l’interface demande un fichier d’entrée, indiquez `server.mjs`; le dossier de sortie est `dist`.

Avant le premier déploiement, créez ces variables d’environnement dans hPanel :

- `ADMIN_MKD_PASSWORD`
- `ADMIN_ICE_PASSWORD`
- `ADMIN_SESSION_SECRET` — une valeur aléatoire d’au moins 32 caractères
- `NODE_ENV` avec la valeur `production`

Après déploiement, vérifiez la boutique, le parcours de commande, puis la connexion à `/admin/connexion` avant de basculer le domaine.
