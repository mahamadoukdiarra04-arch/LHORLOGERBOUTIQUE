(() => {
  const dataNode = document.querySelector('#direct-sale-variants');
  if (!dataNode) return;

  let variantsByProduct = {};
  try {
    variantsByProduct = JSON.parse(dataNode.textContent || '{}');
  } catch (_error) {
    return;
  }

  const setVariantOptions = (line) => {
    const product = line.querySelector('[data-direct-sale-product]');
    const variant = line.querySelector('[data-direct-sale-variant]');
    if (!product || !variant) return;

    const selectedProduct = product.value;
    const previousVariant = variant.value;
    const variants = variantsByProduct[selectedProduct] || [];
    variant.replaceChildren();

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = selectedProduct ? 'Choisir le coloris' : 'Choisissez d’abord le produit';
    variant.append(placeholder);

    variants.forEach((item) => {
      const option = document.createElement('option');
      option.value = String(item.id);
      option.textContent = `${item.name} · stock ${item.stock}`;
      variant.append(option);
    });

    variant.disabled = !selectedProduct || variants.length === 0;
    variant.required = product.required || selectedProduct !== '';
    if (variants.some((item) => String(item.id) === previousVariant)) {
      variant.value = previousVariant;
    } else if (variants.length === 1) {
      variant.value = String(variants[0].id);
    }
  };

  document.querySelectorAll('[data-direct-sale-line]').forEach((line) => {
    const product = line.querySelector('[data-direct-sale-product]');
    setVariantOptions(line);
    product?.addEventListener('change', () => setVariantOptions(line));
  });
})();
