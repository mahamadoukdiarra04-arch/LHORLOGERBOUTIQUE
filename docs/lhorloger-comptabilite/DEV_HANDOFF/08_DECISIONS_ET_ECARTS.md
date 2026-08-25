# Décisions L’Horloger et écarts à ne pas perdre

## 1. Référence de commande sans table entête

L’Horloger possède une ligne `orders` par produit, alors que le panier peut créer plusieurs lignes sous une même `order_ref`. Toute règle financière opère donc sur **la référence entière**. Il est interdit d’encaisser, de livrer ou de calculer le reliquat d’une seule ligne isolée.

## 2. Livraison gratuite aujourd’hui

Le checkout affiche « Livraison offerte ». Il n’existe donc pas de revenu ou coût de livraison dans les allocations V1. Si des frais deviennent facturés, ils doivent être une ligne Boutique distincte et non être ajoutés au prix d’une montre.

## 3. Front commercial et closeuse

La closeuse valide commercialement une commande et prépare le bordereau livreur. Elle ne saisit ni solde, ni décaissement, ni encaissement, ni rapprochement. L’étape comptable appartient au gestionnaire qui rend la référence `Livrée` après encaissement ou exception motivée.

## 4. Comptes et soldes sans données inventées

Les comptes sont créés avec les noms et soldes réels renseignés par la gestion. Aucune commande historique ne devient un encaissement automatiquement. Cette règle protège l’intégrité des chiffres et évite de réintroduire les montants fictifs retirés précédemment de l’admin.

## 5. Coûts Meta existants

`ad_costs` sert déjà à renseigner des montants Meta par produit/période. Ce n’est pas encore un grand livre avec compte débité et justificatif. La comptabilité doit :

- laisser l’historique en place ;
- ne pas l’additionner avec les nouveaux décaissements ;
- proposer une reprise explicite et idempotente seulement si souhaitée ;
- diriger les nouvelles saisies vers le parcours Décaissement afin de connaître le compte réellement débité.

## 6. Réassort volontairement simple

Le réassort actuel renseigne quantité, achat et transit. La comptabilité ne doit pas créer de réservations, de bons de réassort ou de gestion fournisseur lourde. Elle rattache simplement le paiement réel au réassort/produit et fige le coût de vente lors de la sortie.

## 7. Aucun export tableur en V1

Les exports Excel et CSV ont été exclus du périmètre d’administration. Ne pas les réintroduire dans le Journal. Une impression/PDF pourra être étudiée plus tard si le besoin devient réel ; ce n’est pas un livrable V1.

## 8. Actions confirmées et stock

Le code actuel protège certaines sorties avec `stock_processed` et des notes. La version comptable doit remplacer cette idempotence fragile par des sources strictes (`order_id` ou `direct_sale_item_id`) et des contraintes uniques. Les historiques restent lisibles ; aucune déduction ne doit être faite deux fois.

## 9. Charges et rentabilité

La marge actuelle de `analysis.php` mélange coût unitaire et ads. Après activation :

- les nouveaux coûts réellement payés proviennent du Journal ;
- les charges d’une montre restent directement visibles sur cette montre ;
- les charges Boutique ne sont pas réparties arbitrairement ;
- le coût d’achat/transit est reconnu une fois, au rythme des ventes ;
- les prévisions et historiques non repris doivent être clairement badgés, jamais mélangés au réalisé.

## 10. Questions à trancher avant mise en service

Ces points ne bloquent pas le développement du noyau, mais doivent être renseignés avant l’activation :

1. noms des comptes réels et soldes d’ouverture à la date choisie ;
2. catégories de dépenses réellement utilisées ;
3. seuil de justificatif et types de pièces acceptés ;
4. personnes autorisées à contrepasser ou accepter un solde négatif ;
5. politique de remboursement et retour physique de montre ;
6. besoin éventuel de frais de livraison payants à l’avenir ;
7. priorité d’une future prévision avancée basée sur le TED.

## 11. Prototype éventuel : erreurs à éviter

Un éventuel prototype visuel peut aider l’UI, mais il ne doit pas :

- utiliser des données en mémoire comme source de vérité ;
- attribuer toute une référence multi-produits au premier produit ;
- compter une vente entière sur chaque moyen de paiement ;
- marquer une opération comme « supprimée » au lieu de contrepasser ;
- déduire du stock avec `GREATEST(..., 0)` et masquer une rupture ;
- afficher le CA des commandes livrées en plus du CA comptable ;
- exposer une pièce justificative par un chemin public ;
- permettre aux closeuses de voir les comptes de la boutique.
