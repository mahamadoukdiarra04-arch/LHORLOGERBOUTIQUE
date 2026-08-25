# Spécification fonctionnelle — Comptabilité L’Horloger

## 1. Finalité

Le module donne une image exacte de la trésorerie et de la rentabilité réellement observée. Il ne remplace pas les pages commerciales : il devient leur source financière commune.

Les lectures principales sont séparées :

- **Trésorerie** : quand et sur quel compte l’argent est entré ou sorti ;
- **TED — Rentabilité** : ce que les ventes encaissées ont réellement produit après coût des montres et charges.

Un réassort payé peut réduire la trésorerie aujourd’hui, sans réduire deux fois le résultat. Son coût sera reconnu dans le TED lorsque les montres correspondantes seront vendues.

## 2. Comptes de trésorerie

Types supportés : `cash`, `bank`, `mobile_money`.

Le gestionnaire peut créer plusieurs comptes de même type, par exemple **Caisse livreur**, **Banque**, **Orange Money L’Horloger** et **Moov Money L’Horloger**. Un compte comprend un nom, un code stable, un solde d’ouverture, une date d’ouverture, une description et un état actif/inactif.

Le solde affiché est toujours calculé, jamais saisi directement après l’initialisation :

```text
solde d’ouverture
+ encaissements confirmés
− décaissements confirmés
+ transferts reçus
− transferts envoyés
± ajustements confirmés
```

## 3. Passage d’une commande à Livrée

`orders` contient une ligne par montre ; toutes les lignes ayant la même `order_ref` constituent une vente client unique. Le passage à **Livrée** doit donc cibler une référence entière et non une ligne isolée.

### Cas normal

1. le gestionnaire clique **Livrer & encaisser** ;
2. le système charge toutes les lignes de la référence, les montants, les paiements précédents et le reliquat ;
3. le gestionnaire sélectionne un ou plusieurs comptes et répartit le reliquat ;
4. le serveur valide que la somme est exacte ;
5. une transaction crée les opérations, leurs allocations par montre, les sorties de stock uniques et met toutes les lignes au statut `Livrée`.

### Exception : paiement à régulariser

Une commande peut être remise avec un paiement partiel ou nul. L’écran exige alors :

- un motif non vide ;
- les paiements réellement reçus, le cas échéant ;
- une confirmation explicite du reliquat.

La référence devient `Livrée`, le stock sort, les encaissements réellement reçus sont comptés et une alerte **À régulariser** persiste jusqu’au paiement complet, remboursement ou annulation documentée.

La closeuse ne voit pas ce parcours. Son statut **Confirmée** reste une validation commerciale, non un encaissement.

## 4. Encaissements depuis Comptabilité

### Régularisation d’une livraison

Recherche par référence, client ou téléphone. Seules les références `Livrée` avec reliquat sont proposées. Chaque paiement ajoute une opération au compte choisi et couvre uniquement la part restant à encaisser.

### Vente directe

Une vente faite en boutique, via WhatsApp ou par un livreur peut ne pas exister dans le site. Le gestionnaire crée alors une vente directe avec :

- date, client/téléphone facultatifs et canal facultatif ;
- une ou plusieurs montres, coloris libre en note, quantités et prix réellement encaissés ;
- remise éventuelle ;
- un ou plusieurs paiements totalisant le montant calculé serveur ;
- déduction de stock activée par défaut.

Si le stock manque, l’enregistrement avec déduction est bloqué. L’enregistrement sans déduction exige un motif et crée une alerte. La vente directe ne crée pas de faux `orders` ni de deuxième encaissement lorsqu’elle est ultérieurement rattachée à une référence.

### Autre encaissement

Pour apport, remboursement fournisseur ou revenu exceptionnel. Une vente de montre doit toujours passer par Livraison/régularisation ou Vente directe afin d’être correctement affectée à un produit.

## 5. Décaissements

Le formulaire comporte date, montant, compte débité, bénéficiaire, catégorie, libellé, note, référence de paiement et justificatif facultatif.

Le traitement dépend de la catégorie :

| Exemple | Portée requise | Effet TED |
|---|---|---|
| Publicité Meta d’une montre | Produit | Charge directe |
| Shooting/emballage particulier | Produit | Charge directe |
| Loyer, connexion, frais bancaires | Boutique | Charge commune |
| Achat de montres / transit | Produit ou réassort lié | Stock, pas charge immédiate |
| Apport / retrait propriétaire | Boutique | Hors résultat |

Une charge produit impose exactement une montre. Une charge Boutique n’accepte pas de montre. Un solde négatif n’est permis qu’après consentement explicite et note obligatoire.

## 6. Transferts, remboursement et correction

- **Transfert** : compte source et destination distincts ; effet nul sur le CA et le résultat ; frais éventuels en décaissement séparé.
- **Remboursement** : lié à une référence ou vente directe, dans la limite du montant net encaissé ; le retour en stock est une décision physique distincte.
- **Contrepassation** : crée l’opération inverse et les allocations inverses. Elle ne supprime ni la source ni son historique.

## 7. Journal et alertes

Le Journal est la source de vérité de tous les mouvements confirmés. Il prend en charge recherche, période, compte, nature, catégorie, montre, portée, statut et auteur. Les brouillons sont visibles mais n’entrent pas dans les totaux.

Alertes attendues : paiement à régulariser, vente directe sans déduction de stock, charge non affectée, compte négatif, dépense sans justificatif au-dessus du seuil choisi, ancienne écriture brouillon et rapprochement à refaire.

## 8. TED et intégration existante

Le TED se calcule sur la période choisie. Par montre : CA encaissé net, coût des sorties historiques, marge brute, charges directes, contribution et taux. Au niveau boutique : somme des contributions + revenus boutique − charges communes.

Après activation, les montants « réalisés » de `admin/index.php` et `admin/analysis.php` doivent provenir du grand livre. Les données `ad_costs` restent visibles comme historique/prévision tant qu’elles ne sont pas rattachées à un décaissement comptable ; elles ne doivent pas être additionnées deux fois.

## 9. Droits

| Action | Manager | Closeuse |
|---|---:|---:|
| Lire Comptabilité | Oui | Non |
| Créer/valider/contrepasser une opération | Oui | Non |
| Configurer comptes et catégories | Oui | Non |
| Confirmer une commande commerciale | Oui, selon parcours | Oui, dans son suivi |
| Livrer et encaisser | Oui | Non |

## 10. Contraintes de qualité

- pas d’export Excel/CSV dans cette version ;
- aucun montant de démonstration ;
- montants FCFA entiers, affichés avec séparateurs de milliers ;
- action financière validée côté serveur, protégée CSRF et atomique ;
- formulaires et Journal utilisables à 390 px sans débordement du contenu.
