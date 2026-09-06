(function () {
  'use strict';

  function loadImage(source) {
    return new Promise(function (resolve, reject) {
      var picture = new Image();
      picture.onload = function () { resolve(picture); };
      picture.onerror = function () { reject(new Error('La photo de la montre ne peut pas être chargée.')); };
      picture.src = new URL(source, window.location.href).href;
    });
  }

  function canvasBlob(canvas) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob);
        else reject(new Error('La fiche illustrée ne peut pas être créée.'));
      }, 'image/jpeg', 0.92);
    });
  }

  function drawContained(context, picture, x, y, width, height) {
    var ratio = Math.min(width / picture.naturalWidth, height / picture.naturalHeight);
    var targetWidth = picture.naturalWidth * ratio;
    var targetHeight = picture.naturalHeight * ratio;
    context.drawImage(picture, x + (width - targetWidth) / 2, y + (height - targetHeight) / 2, targetWidth, targetHeight);
  }

  async function createDeliveryImage(button) {
    var picture = await loadImage(button.dataset.imageUrl);
    var canvas = document.createElement('canvas');
    canvas.width = 1080;
    canvas.height = 1350;
    var context = canvas.getContext('2d');
    if (!context) throw new Error('La fiche illustrée ne peut pas être créée sur cet appareil.');

    context.fillStyle = '#f5f1e9';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#112b4b';
    context.fillRect(0, 0, canvas.width, 150);
    context.fillStyle = '#d6aa62';
    context.fillRect(0, 146, canvas.width, 4);
    context.fillStyle = '#ffffff';
    context.textAlign = 'center';
    context.font = '700 54px Arial, sans-serif';
    context.fillText('L’HORLOGER', 540, 94);

    context.fillStyle = '#ffffff';
    context.fillRect(60, 180, 960, 650);
    drawContained(context, picture, 85, 205, 910, 600);

    context.textAlign = 'left';
    context.fillStyle = '#a36d20';
    context.font = '700 25px Arial, sans-serif';
    context.fillText('COMMANDE À LIVRER · ' + button.dataset.reference, 70, 885);
    context.fillStyle = '#112b4b';
    context.font = '700 46px Arial, sans-serif';
    context.fillText(button.dataset.product, 70, 945);
    context.fillStyle = '#43566e';
    context.font = '700 30px Arial, sans-serif';
    context.fillText('Couleur : ' + button.dataset.variant + ' · Qté ' + button.dataset.quantity, 70, 995);
    context.fillStyle = '#112b4b';
    context.font = '700 28px Arial, sans-serif';
    context.fillText('Client : ' + button.dataset.customer, 70, 1043);
    context.fillStyle = '#43566e';
    context.font = '600 27px Arial, sans-serif';
    context.fillText(button.dataset.phone + ' · ' + button.dataset.district, 70, 1087);

    context.fillStyle = '#112b4b';
    context.fillRect(60, 1130, 960, 170);
    context.fillStyle = '#ffffff';
    context.textAlign = 'center';
    context.font = '700 25px Arial, sans-serif';
    context.fillText('PRIX À ENCAISSER', 540, 1177);
    context.font = '700 70px Arial, sans-serif';
    context.fillText(button.dataset.price, 540, 1265);

    return canvasBlob(canvas);
  }

  function setShareStatus(form, message, isError) {
    var status = form.querySelector('[data-share-status]');
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-error', Boolean(isError));
  }

  function revealFallback(form) {
    var confirmButton = form.querySelector('[data-whatsapp-fallback-confirm]');
    if (confirmButton) confirmButton.hidden = false;
    setShareStatus(form, 'Après l’envoi dans WhatsApp, confirmez ci-dessous pour passer la commande en livraison.', false);
  }

  document.addEventListener('click', async function (event) {
    var fallbackLink = event.target.closest('[data-whatsapp-fallback-link]');
    if (fallbackLink) {
      var fallbackForm = fallbackLink.closest('[data-whatsapp-send-form]');
      if (fallbackForm) revealFallback(fallbackForm);
      return;
    }

    var button = event.target.closest('[data-whatsapp-share]');
    if (!button) return;
    var form = button.closest('[data-whatsapp-send-form]');
    if (!form) return;

    var originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Préparation de la fiche…';
    setShareStatus(form, '', false);

    try {
      if (!navigator.share || typeof window.File !== 'function') {
        revealFallback(form);
        return;
      }
      var blob = await createDeliveryImage(button);
      var safeReference = (button.dataset.reference || 'commande').replace(/[^a-zA-Z0-9_-]+/g, '-');
      var file = new File([blob], 'livraison-' + safeReference + '.jpg', { type: 'image/jpeg' });
      var shareData = {
        title: 'Livraison L’Horloger',
        text: button.dataset.message,
        files: [file]
      };
      if (navigator.canShare && !navigator.canShare(shareData)) {
        revealFallback(form);
        return;
      }
      await navigator.share(shareData);
      setShareStatus(form, 'Partage terminé. Passage de la commande en livraison…', false);
      form.requestSubmit();
    } catch (error) {
      if (error && error.name === 'AbortError') {
        setShareStatus(form, 'Partage annulé : la commande reste dans votre suivi.', false);
      } else {
        revealFallback(form);
        setShareStatus(form, 'Le partage de la photo n’est pas disponible ici. Utilisez « Ouvrir WhatsApp en texte », puis confirmez l’envoi.', true);
      }
    } finally {
      if (form.dataset.submitting !== 'true') {
        button.disabled = false;
        button.textContent = originalLabel;
      }
    }
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.dataset.submitting === 'true') {
      event.preventDefault();
      return;
    }

    form.dataset.submitting = 'true';
    window.requestAnimationFrame(function () {
      var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
      buttons.forEach(function (submitButton) {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-disabled', 'true');
      });
    });
  });
}());
