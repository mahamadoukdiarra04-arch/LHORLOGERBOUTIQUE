(() => {
  const form = document.querySelector('[data-quick-selection]');
  if (form) {
    const boxes = Array.from(form.querySelectorAll('[data-quick-order]'));
    const all = form.querySelector('[data-quick-all]');
    const action = form.querySelector('[data-quick-action]');
    const money = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
    const update = () => {
      const selected = boxes.filter(box => box.checked);
      const financial = ['deliver', 'collect'].includes(action.value);
      boxes.forEach(box => box.closest('.quick-order-card').classList.toggle('is-selected', box.checked));
      all.checked = boxes.length > 0 && selected.length === boxes.length;
      all.indeterminate = selected.length > 0 && selected.length < boxes.length;
      form.querySelector('[data-quick-count]').textContent = `${selected.length} commande(s) sélectionnée(s)`;
      form.querySelector('[data-quick-total]').textContent = financial
        ? `${money.format(selected.reduce((sum, box) => sum + Number(box.dataset.amount), 0))} FCFA à encaisser`
        : 'Statuts uniquement · aucun encaissement';
      form.querySelector('[data-quick-preview]').disabled = selected.length === 0;
      form.querySelectorAll('[data-quick-financial]').forEach(label => {
        label.hidden = !financial;
        label.querySelectorAll('input,select').forEach(input => { input.disabled = !financial; input.required = financial; });
      });
      const channel = form.querySelector('[data-quick-channel]');
      channel.hidden = ['cancel', 'collect'].includes(action.value);
      channel.querySelector('select').disabled = channel.hidden;
    };
    all.addEventListener('change', () => { boxes.forEach(box => { box.checked = all.checked; }); update(); });
    form.addEventListener('change', update);
    form.addEventListener('submit', () => {
      const button = form.querySelector('[data-quick-preview]');
      button.disabled = true;
      button.textContent = 'Préparation du récapitulatif…';
    });
    update();
  }
  const confirm = document.querySelector('[data-quick-confirm]');
  confirm?.addEventListener('submit', () => {
    const button = confirm.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Enregistrement du lot…';
    confirm.querySelector('[data-quick-submit-status]').textContent = 'Patientez. Ne fermez pas cette page pendant la validation.';
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) window.location.reload();
  });
})();
