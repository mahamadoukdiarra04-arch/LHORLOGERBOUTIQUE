(function () {
  'use strict';

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
      buttons.forEach(function (button) {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      });
    });
  });
}());
