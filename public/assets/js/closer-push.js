(() => {
  const panel = document.querySelector('[data-push-panel]');
  if (!panel) return;
  const status = panel.querySelector('[data-push-status]');
  const enable = panel.querySelector('[data-push-enable]');
  const test = panel.querySelector('[data-push-test]');
  const disable = panel.querySelector('[data-push-disable]');
  let registration, config, subscription;
  const message = text => { status.textContent = text; };
  const key = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  async function post(action) {
    const response = await fetch('/closer/push.php', {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({action, csrf: config.csrf, subscription: JSON.stringify(subscription)})});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Action impossible. Reconnectez-vous puis réessayez.');
  }
  function buttons(active) { enable.hidden = active; test.hidden = !active; disable.hidden = !active; }
  async function init() {
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios && !(navigator.standalone || matchMedia('(display-mode: standalone)').matches)) {
      enable.hidden = true;
      message('Sur iPhone : Safari → Partager → Ajouter à l’écran d’accueil. Ouvrez ensuite l’icône L’Horloger pour activer les notifications.'); return;
    }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      enable.hidden = true; message('Notifications indisponibles sur ce navigateur. Utilisez un navigateur récent.'); return;
    }
    const response = await fetch('/closer/push.php', {cache: 'no-store'});
    config = await response.json();
    if (!response.ok) throw new Error(config.error || 'Configuration indisponible.');
    registration = await navigator.serviceWorker.register('/closer/sw.js', {scope: '/closer/'});
    registration = await navigator.serviceWorker.ready;
    subscription = await registration.pushManager.getSubscription();
    if (subscription && Notification.permission === 'granted') { await post('subscribe'); buttons(true); message('Notifications activées sur cet appareil.'); }
    else { buttons(false); message(Notification.permission === 'denied' ? 'Notifications bloquées. Autorisez L’Horloger dans les réglages du téléphone, puis rechargez.' : 'Recevez les nouvelles commandes même lorsque cet espace est fermé.'); }
    enable.disabled = false;
  }
  enable.addEventListener('click', async () => {
    enable.disabled = true;
    try {
      // Request immediately from the user's gesture (required on iPhone).
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('Autorisez les notifications dans les réglages pour les recevoir.');
      subscription = await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: key(config.publicKey)});
      await post('subscribe'); buttons(true); message('Notifications activées. Appuyez sur Tester pour vérifier la réception.');
    } catch (error) { message(error.message); } finally { enable.disabled = false; }
  });
  test.addEventListener('click', async () => {
    test.disabled = true;
    try { await post('test'); message('Test accepté par le service push. Vérifiez votre centre de notifications.'); }
    catch (error) { message(error.message); } finally { test.disabled = false; }
  });
  disable.addEventListener('click', async () => {
    disable.disabled = true;
    try { await post('unsubscribe'); await subscription.unsubscribe(); subscription = null; buttons(false); message('Notifications désactivées sur cet appareil.'); }
    catch (error) { message(error.message); } finally { disable.disabled = false; }
  });
  init().catch(error => message(error.message || 'Notifications indisponibles. Rechargez la page.'));
})();
