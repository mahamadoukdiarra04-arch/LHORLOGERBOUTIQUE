(() => {
  const dataNode = document.querySelector('#direct-sale-variants');
  if (!dataNode) return;

  let variantsByProduct = {};
  try {
    variantsByProduct = JSON.parse(dataNode.textContent || '{}');
  } catch (_error) {
    return;
  }

  const form = dataNode.closest('.accounting-action-panel')?.querySelector('form');
  const lineContainer = form?.querySelector('[data-direct-sale-lines]');
  const paymentContainer = form?.querySelector('[data-direct-sale-payment-lines]');
  const lineTemplate = form?.querySelector('#direct-sale-line-template');
  const paymentTemplate = form?.querySelector('#direct-sale-payment-template');
  if (!form || !lineContainer || !paymentContainer || !lineTemplate || !paymentTemplate) return;

  const moneyFormatter = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
  const money = (value) => `${moneyFormatter.format(Math.max(0, Math.round(value)))} FCFA`;
  const numericValue = (input) => {
    const value = Number.parseInt(input?.value || '0', 10);
    return Number.isFinite(value) && value > 0 ? value : 0;
  };

  const renderTemplate = (template, index, number) => {
    const holder = document.createElement('template');
    holder.innerHTML = template.innerHTML
      .replaceAll('__INDEX__', String(index))
      .replaceAll('__NUMBER__', String(number))
      .trim();
    return holder.content.firstElementChild;
  };

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
    variant.required = true;
    if (variants.some((item) => String(item.id) === previousVariant)) {
      variant.value = previousVariant;
    } else if (variants.length === 1) {
      variant.value = String(variants[0].id);
    }
  };

  const calculateLine = (line) => {
    const quantity = numericValue(line.querySelector('[data-direct-sale-quantity]'));
    const price = numericValue(line.querySelector('[data-direct-sale-price]'));
    const discountInput = line.querySelector('[data-direct-sale-discount]');
    const discount = numericValue(discountInput);
    const gross = quantity * price;
    const invalidDiscount = gross > 0 && discount >= gross;
    const net = invalidDiscount ? 0 : Math.max(0, gross - discount);

    discountInput?.setCustomValidity(invalidDiscount ? 'La remise doit rester inférieure au sous-total de la ligne.' : '');
    line.classList.toggle('is-invalid', invalidDiscount);
    line.dataset.gross = String(gross);
    line.dataset.discount = String(discount);
    line.dataset.net = String(net);

    const totalNode = line.querySelector('[data-direct-sale-line-total]');
    const breakdownNode = line.querySelector('[data-direct-sale-line-breakdown]');
    if (totalNode) totalNode.textContent = money(net);
    if (breakdownNode) {
      if (invalidDiscount) {
        breakdownNode.textContent = 'La remise doit être inférieure au sous-total.';
      } else if (gross > 0) {
        breakdownNode.textContent = discount > 0
          ? `${money(gross)} − ${money(discount)} de remise`
          : `${money(gross)} sans remise`;
      } else {
        breakdownNode.textContent = 'Choisissez un produit.';
      }
    }
  };

  const paymentRows = () => Array.from(paymentContainer.querySelectorAll('[data-direct-sale-payment-line]'));
  const productRows = () => Array.from(lineContainer.querySelectorAll('[data-direct-sale-line]'));

  const updateRowControls = () => {
    const products = productRows();
    products.forEach((line, index) => {
      const title = line.querySelector('[data-line-title]');
      const remove = line.querySelector('[data-remove-direct-sale-line]');
      if (title) title.textContent = `Produit ${index + 1}`;
      if (remove) remove.hidden = products.length === 1;
    });

    const payments = paymentRows();
    payments.forEach((line, index) => {
      const title = line.querySelector('[data-payment-title]');
      const remove = line.querySelector('[data-remove-direct-sale-payment]');
      if (title) title.textContent = `Encaissement ${index + 1}`;
      if (remove) remove.hidden = payments.length === 1;
    });
  };

  const paymentTotal = () => paymentRows().reduce(
    (sum, line) => sum + numericValue(line.querySelector('[data-direct-sale-payment-amount]')),
    0
  );

  const syncSinglePayment = (netTotal) => {
    const payments = paymentRows();
    payments.forEach((line) => {
      const amount = line.querySelector('[data-direct-sale-payment-amount]');
      if (amount) amount.readOnly = payments.length === 1;
    });
    if (payments.length !== 1) return;
    const amount = payments[0].querySelector('[data-direct-sale-payment-amount]');
    if (amount) amount.value = netTotal > 0 ? String(netTotal) : '';
  };

  const updatePaymentStatus = (netTotal) => {
    const status = form.querySelector('[data-direct-sale-payment-status]');
    const submit = form.querySelector('button.admin-button:not([type="button"])');
    const received = paymentTotal();
    const difference = netTotal - received;
    const hasInvalidLine = productRows().some((line) => line.classList.contains('is-invalid'));
    const hasIncompleteLine = productRows().some((line) => {
      const product = line.querySelector('[data-direct-sale-product]');
      const variant = line.querySelector('[data-direct-sale-variant]');
      return !product?.value || !variant?.value
        || numericValue(line.querySelector('[data-direct-sale-quantity]')) === 0
        || numericValue(line.querySelector('[data-direct-sale-price]')) === 0;
    });
    const hasIncompletePayment = paymentRows().some((line) => (
      !line.querySelector('select')?.value
      || numericValue(line.querySelector('[data-direct-sale-payment-amount]')) === 0
    ));
    const selectedAccounts = paymentRows()
      .map((line) => line.querySelector('select')?.value || '')
      .filter(Boolean);
    const hasDuplicateAccount = new Set(selectedAccounts).size !== selectedAccounts.length;

    status?.classList.remove('is-balanced', 'is-warning');
    if (hasIncompleteLine && netTotal > 0) {
      status?.classList.add('is-warning');
      if (status) status.textContent = 'Complétez ou retirez chaque ligne produit avant de confirmer.';
    } else if (netTotal <= 0) {
      if (status) status.textContent = 'Renseignez les produits pour calculer le montant à encaisser.';
    } else if (hasIncompletePayment) {
      status?.classList.add('is-warning');
      if (status) status.textContent = 'Choisissez le compte et complétez chaque encaissement.';
    } else if (hasDuplicateAccount) {
      status?.classList.add('is-warning');
      if (status) status.textContent = 'Chaque compte doit apparaître une seule fois dans les encaissements.';
    } else if (difference === 0) {
      status?.classList.add('is-balanced');
      if (status) status.textContent = `Encaissement équilibré · ${money(received)}`;
    } else if (difference > 0) {
      status?.classList.add('is-warning');
      if (status) status.textContent = `Reste à affecter · ${money(difference)}`;
    } else {
      status?.classList.add('is-warning');
      if (status) status.textContent = `Encaissement en trop · ${money(Math.abs(difference))}`;
    }

    if (submit) submit.disabled = netTotal <= 0 || difference !== 0 || hasInvalidLine || hasIncompleteLine || hasIncompletePayment || hasDuplicateAccount;
  };

  const updateTotals = () => {
    let grossTotal = 0;
    let discountTotal = 0;
    let netTotal = 0;
    productRows().forEach((line) => {
      calculateLine(line);
      grossTotal += Number(line.dataset.gross || 0);
      discountTotal += Number(line.dataset.discount || 0);
      netTotal += Number(line.dataset.net || 0);
    });

    const subtotalNode = form.querySelector('[data-direct-sale-subtotal]');
    const discountNode = form.querySelector('[data-direct-sale-discount-total]');
    const totalNode = form.querySelector('[data-direct-sale-total]');
    if (subtotalNode) subtotalNode.textContent = money(grossTotal);
    if (discountNode) discountNode.textContent = `− ${money(discountTotal)}`;
    if (totalNode) totalNode.textContent = money(netTotal);

    syncSinglePayment(netTotal);
    updatePaymentStatus(netTotal);
  };

  let nextLineIndex = 1;
  let nextPaymentIndex = 1;

  form.addEventListener('change', (event) => {
    const product = event.target.closest?.('[data-direct-sale-product]');
    if (!product) {
      if (event.target.closest?.('[data-direct-sale-payment-line]')) {
        const netTotal = productRows().reduce((sum, line) => sum + Number(line.dataset.net || 0), 0);
        updatePaymentStatus(netTotal);
      }
      return;
    }
    const line = product.closest('[data-direct-sale-line]');
    if (!line) return;
    setVariantOptions(line);
    const selected = product.selectedOptions[0];
    const price = line.querySelector('[data-direct-sale-price]');
    if (price) price.value = selected?.dataset.price || '';
    updateTotals();
  });

  form.addEventListener('input', (event) => {
    if (event.target.closest?.('[data-direct-sale-line]')) {
      updateTotals();
      return;
    }
    if (event.target.matches?.('[data-direct-sale-payment-amount]')) {
      const netTotal = productRows().reduce((sum, line) => sum + Number(line.dataset.net || 0), 0);
      updatePaymentStatus(netTotal);
    }
  });

  form.addEventListener('click', (event) => {
    const addLine = event.target.closest?.('[data-add-direct-sale-line]');
    if (addLine) {
      if (productRows().length >= 50) return;
      const line = renderTemplate(lineTemplate, nextLineIndex, productRows().length + 1);
      nextLineIndex += 1;
      lineContainer.append(line);
      setVariantOptions(line);
      updateRowControls();
      updateTotals();
      line.querySelector('[data-direct-sale-product]')?.focus();
      return;
    }

    const removeLine = event.target.closest?.('[data-remove-direct-sale-line]');
    if (removeLine && productRows().length > 1) {
      removeLine.closest('[data-direct-sale-line]')?.remove();
      updateRowControls();
      updateTotals();
      return;
    }

    const addPayment = event.target.closest?.('[data-add-direct-sale-payment]');
    if (addPayment) {
      if (paymentRows().length >= 20) return;
      const line = renderTemplate(paymentTemplate, nextPaymentIndex, paymentRows().length + 1);
      nextPaymentIndex += 1;
      paymentContainer.append(line);
      updateRowControls();
      updateTotals();
      line.querySelector('select')?.focus();
      return;
    }

    const removePayment = event.target.closest?.('[data-remove-direct-sale-payment]');
    if (removePayment && paymentRows().length > 1) {
      removePayment.closest('[data-direct-sale-payment-line]')?.remove();
      updateRowControls();
      updateTotals();
    }
  });

  productRows().forEach(setVariantOptions);
  updateRowControls();
  updateTotals();
})();
