<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();

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
    <p class="admin-kicker">Comptabilité · Noyau sécurisé</p>
    <h1>Préparer une trésorerie fiable.</h1>
    <p>Les règles de comptes, ventes, dépenses, transferts, remboursements et rapprochements sont prêtes. Aucun solde ni mouvement n’a été créé automatiquement.</p>
  </div>
</header>

<?php if (isset($accountingError)): ?>
  <section class="admin-panel"><p class="flash flash-error"><?= e($accountingError) ?></p></section>
<?php else: ?>
  <section class="metric-grid">
    <article class="metric"><p>Comptes configurés</p><strong><?= $accountingStatus['accounts'] ?></strong><span>Seuls des comptes réels seront ajoutés lors du paramétrage.</span></article>
    <article class="metric"><p>Catégories système</p><strong><?= $accountingStatus['categories'] ?></strong><span>Traitements stables pour le futur TED.</span></article>
    <article class="metric"><p>Mouvements comptables</p><strong><?= $accountingStatus['operations'] ?></strong><span>Seules les actions confirmées y apparaîtront.</span></article>
    <article class="metric"><p>État</p><strong>Prêt</strong><span>Fondation MySQL initialisée sans données fictives.</span></article>
  </section>

  <section class="admin-grid">
    <article class="admin-panel">
      <p class="admin-kicker">Ce qui est déjà sécurisé</p>
      <h2>Une base commune pour les chiffres réels.</h2>
      <p class="admin-copy">Chaque vente, régularisation, dépense, transfert ou remboursement sera relié à une trace complète. Les coûts historiques, les réassorts et le stock restent cohérents avec le Journal.</p>
    </article>
    <article class="admin-panel">
      <p class="admin-kicker">Étape suivante</p>
      <h2>Rendre ces actions accessibles.</h2>
      <p class="admin-copy">Le prochain jalon apporte le Journal, le TED, les paramètres de comptes et les formulaires de gestion, optimisés pour mobile.</p>
    </article>
  </section>

  <section class="admin-panel" style="margin-top:15px">
    <p class="admin-kicker">Principes activés</p>
    <h2>Pas de raccourci sur les données.</h2>
    <div class="events">
      <div class="event"><strong>Références de commande</strong><span>Toutes les lignes partageant une même référence seront traitées comme une vente unique.</span></div>
      <div class="event"><strong>Historique</strong><span>Les opérations confirmées sont conçues pour être contrepassées, jamais supprimées silencieusement.</span></div>
      <div class="event"><strong>Coûts Meta</strong><span>Les coûts existants ne seront pas recopiés automatiquement dans la trésorerie.</span></div>
    </div>
  </section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
