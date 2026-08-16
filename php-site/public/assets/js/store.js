(() => {
  const key = 'lhorloger_cart_v1';
  const read = () => {
    try { return JSON.parse(localStorage.getItem(key) || '[]'); }
    catch { return []; }
  };
  const update = () => document.querySelectorAll('[data-cart-count]').forEach((element) => {
    element.textContent = read().reduce((count, line) => count + Number(line.quantity || 0), 0);
  });
  const write = (lines) => { localStorage.setItem(key, JSON.stringify(lines)); update(); };

  window.LHorlogerCart = {
    read,
    write,
    add: (line) => {
      const lines = read();
      const existing = lines.find((item) => item.slug === line.slug && item.variant === line.variant);
      if (existing) existing.quantity += Number(line.quantity || 1);
      else lines.push({ ...line, quantity: Number(line.quantity || 1) });
      write(lines);
    },
    remove: (index) => { const lines = read(); lines.splice(index, 1); write(lines); },
  };

  const setGalleryMain = (source, alt = '') => {
    const main = document.querySelector('[data-gallery-main]');
    if (!main || !source) return;
    main.src = source;
    main.alt = alt;
  };

  update();
  document.querySelectorAll('[data-gallery-thumb]').forEach((button) => button.addEventListener('click', () => {
    setGalleryMain(button.dataset.galleryThumb, button.dataset.galleryAlt);
  }));
  document.querySelectorAll('[data-variant-image]').forEach((input) => input.addEventListener('change', () => {
    if (input.checked) setGalleryMain(input.dataset.variantImage, input.dataset.variantAlt);
  }));
})();
