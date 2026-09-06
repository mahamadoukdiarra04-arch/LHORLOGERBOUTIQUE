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

  const bindGalleryThumb = (button) => button.addEventListener('click', () => {
    setGalleryMain(button.dataset.galleryThumb, button.dataset.galleryAlt);
  });

  const setGallery = (sources, alt = '') => {
    const gallery = document.querySelector('[data-gallery-thumbs]');
    const uniqueSources = [...new Set(sources.filter((source) => typeof source === 'string' && source))];
    if (!uniqueSources.length) return;

    setGalleryMain(uniqueSources[0], alt);
    if (!gallery) return;

    const fragment = document.createDocumentFragment();
    uniqueSources.forEach((source, index) => {
      const button = document.createElement('button');
      const image = document.createElement('img');
      button.type = 'button';
      button.dataset.galleryThumb = source;
      button.dataset.galleryAlt = `${alt}, vue ${index + 1}`;
      button.setAttribute('aria-label', `Afficher la vue ${index + 1}`);
      image.src = source;
      image.alt = '';
      image.loading = 'lazy';
      button.append(image);
      bindGalleryThumb(button);
      fragment.append(button);
    });
    gallery.replaceChildren(fragment);
  };

  update();
  document.querySelectorAll('[data-gallery-thumb]').forEach(bindGalleryThumb);
  document.querySelectorAll('[data-variant-image]').forEach((input) => input.addEventListener('change', () => {
    if (!input.checked) return;
    let gallery = [];
    try { gallery = JSON.parse(input.dataset.variantGallery || '[]'); }
    catch { gallery = []; }
    setGallery(Array.isArray(gallery) && gallery.length ? gallery : [input.dataset.variantImage], input.dataset.variantAlt);
  }));
})();
