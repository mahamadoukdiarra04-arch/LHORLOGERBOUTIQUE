(() => {
  const expense = document.querySelector('[data-simple-expense]');
  if (expense) {
    const category = expense.querySelector('[data-expense-category]');
    const productLabel = expense.querySelector('[data-expense-product]');
    const product = productLabel.querySelector('select');
    const update = () => {
      const option = category.selectedOptions[0];
      const needsProduct = option?.dataset.scope === 'product';
      productLabel.hidden = !needsProduct;
      product.disabled = !needsProduct;
      product.required = needsProduct;
      expense.querySelector('[data-expense-summary]').textContent = !category.value
        ? 'La dépense sera inscrite au journal dès la validation.'
        : needsProduct ? 'Dépense liée au produit sélectionné : elle sera déduite de son résultat.' : 'Dépense de la boutique : elle sera déduite du résultat global.';
    };
    category.addEventListener('change', update);
    update();
  }
  const balance = document.querySelector('[data-simple-balance]');
  if (balance) {
    const reference = balance.querySelector('[name="order_ref"]');
    const amount = balance.querySelector('[name="payments[0][amount_fcfa]"]');
    const update = () => { amount.value = reference.selectedOptions[0]?.dataset.remaining || ''; };
    reference.addEventListener('change', update);
    update();
  }
  // A required field inside advanced options must remain reachable on mobile.
  document.addEventListener('invalid', event => {
    let parent = event.target.closest('details');
    while (parent) { parent.open = true; parent = parent.parentElement.closest('details'); }
  }, true);
})();
