(() => {
  const form = document.querySelector('[data-order-edit-form]');
  const source = document.getElementById('order-edit-catalog');
  if (!form || !source) return;

  let products = [];
  try {
    products = JSON.parse(source.textContent || '[]');
  } catch (_) {
    return;
  }

  const productSelect = form.querySelector('[data-order-edit-product]');
  const variantSelect = form.querySelector('[data-order-edit-variant]');
  const priceInput = form.querySelector('[data-order-edit-price]');
  const quantityInput = form.querySelector('[name="quantity"]');
  const total = form.querySelector('[data-order-edit-total]');
  if (!productSelect || !variantSelect || !priceInput || !quantityInput || !total) return;

  const money = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
  const updateTotal = () => {
    const quantity = Math.max(0, Number.parseInt(quantityInput.value || '0', 10) || 0);
    const price = Math.max(0, Number.parseInt(priceInput.value || '0', 10) || 0);
    total.textContent = `${money.format(quantity * price).replace(/\u202f/g, ' ')} FCFA`;
  };

  const updateProduct = () => {
    const product = products.find((item) => String(item.id) === productSelect.value);
    variantSelect.replaceChildren();
    if (!product) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'Choisir un produit';
      variantSelect.append(option);
      return;
    }
    for (const variant of product.variants || []) {
      const option = document.createElement('option');
      option.value = String(variant.id);
      option.textContent = `${variant.name} · stock ${variant.stock_quantity}`;
      variantSelect.append(option);
    }
    priceInput.value = String(product.price_fcfa || '');
    updateTotal();
  };

  productSelect.addEventListener('change', updateProduct);
  quantityInput.addEventListener('input', updateTotal);
  priceInput.addEventListener('input', updateTotal);
  updateTotal();
})();
