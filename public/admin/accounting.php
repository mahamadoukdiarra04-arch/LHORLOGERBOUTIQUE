<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
require APP_ROOT . '/accounting.php';

try {
    $accountingStatus = accounting_foundation_status();
} catch (Throwable $exception) {
    error_log('L’Horloger: initialisation comptable échouée.');
    $accountingError = 'La fondation comptable ne peut pas être préparée pour le moment. Réessayez dans quelques instants.';
}

$adminPageTitle = 'Comptabilité';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Comptabilité · Phase 1</p>
    <h1>Préparer une trésorerie fiable.</h1>
    <p>La structure des comptes, catégories, opérations et liens stock est prête. Aucun solde ni mouvement n’a été créé automatiquement.</p>
  </div>
</header>

<?php if (isset($accountingError)): ?>
  <section class="admin-panel"><p class="flash flash-error"><?= e($accountingError) ?></p></section>
<?php else: ?>
  <section class="metric-grid">
    <article class="metric"><p>Comptes configurés</p><strong><?= $accountingStatus['accounts'] ?></strong><span>Les comptes réels seront renseignés à la phase suivante.</span></article>
    <article class="metric"><p>Catégories système</p><strong><?= $accountingStatus['categories'] ?></strong><span>Traitements stables pour le futur TED.</span></article>
    <article class="metric"><p>Mouvements comptables</p><strong><?= $accountingStatus['operations'] ?></strong><span>Doit rester à zéro avant les actions financières.</span></article>
    <article class="metric"><p>État</p><strong>Prêt</strong><span>Fondation MySQL initialisée sans données fictives.</span></article>
  </section>

  <section class="admin-grid">
    <article class="admin-panel">
      <p class="admin-kicker">Ce qui est déjà sécurisé</p>
      <h2>Une base commune pour les chiffres réels.</h2>
      <p class="admin-copy">Les futures opérations seront reliées à une référence de commande complète, jamais à une seule ligne du panier. Les coûts historiques, les réassorts, les dépenses Meta et le stock disposent désormais des emplacements nécessaires pour rester cohérents.</p>
    </article>
    <article class="admin-panel">
      <p class="admin-kicker">Étape suivante</p>
      <h2>Configurer les comptes réels.</h2>
      <p class="admin-copy">La prochaine phase permettra de créer Caisse, Banque et portefeuilles mobile money avec leurs soldes d’ouverture réels, puis d’enregistrer les premiers encaissements et décaissements.</p>
    </article>
  </section>

  <section class="admin-panel" style="margin-top:15px">
    <p class="admin-kicker">Principes activés</p>
    <h2>Pas de raccourci sur les données.</h2>
    <div class="events">
      <div class="event"><strong>Références de commande</strong><span>Toutes les lignes partageant une même référence seront traitées comme une vente unique.</span></div>
      <div class="event"><strong>Historique</strong><span>Les opérations confirmées seront contrepassées, jamais supprimées silencieusement.</span></div>
      <div class="event"><strong>Coûts Meta</strong><span>Les coûts existants ne seront pas recopiés automatiquement dans la trésorerie.</span></div>
    </div>
  </section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
