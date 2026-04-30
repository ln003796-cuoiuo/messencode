(() => {
  const app = document.getElementById('wp-messenger-app');
  if (!app || !window.WPMessengerConfig) return;

  const dialogsEl = document.getElementById('wp-messenger-dialogs');
  const messagesEl = document.getElementById('wp-messenger-messages');
  const form = document.getElementById('wp-messenger-form');
  const textInput = document.getElementById('wp-messenger-text');
  const silentInput = document.getElementById('wp-messenger-silent');
  const titleEl = document.getElementById('wp-messenger-title');

  let activeDialogId = null;

  async function api(path, options = {}) {
    const response = await fetch(`${WPMessengerConfig.root}${path}`, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': WPMessengerConfig.nonce,
        ...(options.headers || {}),
      },
    });
    return response.json();
  }

  function renderDialogs(dialogs) {
    dialogsEl.innerHTML = '';
    dialogs.forEach((dialog) => {
      const li = document.createElement('li');
      li.className = 'dialog-item';
      li.textContent = dialog.title || `Чат #${dialog.id}`;
      li.onclick = () => openDialog(dialog.id, dialog.title || `Чат #${dialog.id}`);
      dialogsEl.appendChild(li);
    });
  }

  function renderMessages(messages) {
    messagesEl.innerHTML = '';
    messages.forEach((m) => {
      const li = document.createElement('li');
      li.className = Number(m.user_id) === Number(WPMessengerConfig.userId) ? 'outgoing' : 'incoming';
      li.innerHTML = `<p>${String(m.text)}</p><small>${m.created_at}</small>`;
      messagesEl.appendChild(li);
    });
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function loadDialogs() {
    const data = await api('/dialogs');
    renderDialogs(data.dialogs || []);
  }

  async function openDialog(dialogId, title) {
    activeDialogId = dialogId;
    titleEl.textContent = title;
    const data = await api(`/dialogs/${dialogId}/messages`);
    renderMessages(data.messages || []);
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = textInput.value.trim();
    if (!activeDialogId || !text) return;

    await api(`/dialogs/${activeDialogId}/messages`, {
      method: 'POST',
      body: JSON.stringify({ text, is_silent: silentInput.checked }),
    });

    textInput.value = '';
    await openDialog(activeDialogId, titleEl.textContent);
  });

  loadDialogs();
  setInterval(() => {
    if (activeDialogId) openDialog(activeDialogId, titleEl.textContent);
    loadDialogs();
  }, 3000);
})();
