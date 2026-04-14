(function (global) {
  const TYPE_CLASSES = [
    'toast-success',
    'toast-error',
    'toast-warning',
    'toast-info',
    'success',
    'error',
    'warning',
    'info'
  ];

  function ensureToastContainer() {
    let toast = document.getElementById('toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'toast';
      toast.className = 'toast';
      toast.style.display = 'none';
      document.body.appendChild(toast);
    }

    if (!toast.querySelector('#toast-message')) {
      const span = document.createElement('span');
      span.id = 'toast-message';
      toast.appendChild(span);
    }

    toast.setAttribute('aria-live', 'polite');
    toast.setAttribute('role', 'status');

    return toast;
  }

  function clearTypeClasses(toast) {
    TYPE_CLASSES.forEach(cls => toast.classList.remove(cls));
  }

  function showToast(message, type = 'info', options = {}) {
    const { duration = 4000 } = options;
    const toast = ensureToastContainer();
    const messageEl = toast.querySelector('#toast-message') || toast;

    if (toast._hideTimeout) {
      clearTimeout(toast._hideTimeout);
    }
    if (toast._cleanupTimeout) {
      clearTimeout(toast._cleanupTimeout);
    }

    messageEl.textContent = message;
    toast.style.display = 'block';
    toast.classList.remove('show');
    void toast.offsetWidth;

    toast.classList.add('toast');
    toast.classList.add('show');

    clearTypeClasses(toast);
    if (type) {
      toast.classList.add(type);
      toast.classList.add(`toast-${type}`);
    }

    toast._hideTimeout = setTimeout(() => {
      toast.classList.remove('show');
      toast._cleanupTimeout = setTimeout(() => {
        clearTypeClasses(toast);
        toast.style.display = 'none';
      }, 350);
    }, duration);
  }

  function ensureConfirmButtonStyles() {
    if (document.getElementById('confirm-button-styles')) return;

    const style = document.createElement('style');
    style.id = 'confirm-button-styles';
    style.textContent = `
      .btn-secondary.confirm-btn {
        background: #6b7280;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 10px 16px;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.95rem;
        font-weight: 500;
        transition: background 0.2s ease;
      }

      .btn-secondary.confirm-btn:hover {
        background: #4b5563;
      }

      .btn-secondary.confirm-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .btn-danger.confirm-btn {
        background: #dc3545;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 10px 16px;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.95rem;
        font-weight: 500;
        transition: background 0.2s ease;
      }

      .btn-danger.confirm-btn:hover {
        background: #c82333;
      }

      .btn-danger.confirm-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
    `;
    document.head.appendChild(style);
  }

  function confirmAction(message, options = {}) {
    if (!global.ModalHelper) {
      return Promise.resolve(false);
    }

    // Ensure button styles are injected globally
    ensureConfirmButtonStyles();

    return new Promise((resolve) => {
      const modalId = `confirm-modal-${Date.now()}`;
      const content = `
        <div style="display:flex;flex-direction:column;gap:16px;">
          <p style="margin:0;color:#374151;font-size:0.95rem;">${message}</p>
          <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="btn-secondary confirm-btn" data-action="cancel">Cancel</button>
            <button type="button" class="btn-danger confirm-btn" data-action="confirm">Confirm</button>
          </div>
        </div>
      `;

      ModalHelper.open({
        id: modalId,
        title: options.title || 'Please Confirm',
        content,
        onOpen: ({ overlay, body }) => {
          const confirmBtn = body.querySelector('[data-action="confirm"]');
          const cancelBtn = body.querySelector('[data-action="cancel"]');

          const cleanup = (result) => {
            ModalHelper.close(overlay.id);
            resolve(result);
          };

          confirmBtn?.addEventListener('click', () => cleanup(true));
          cancelBtn?.addEventListener('click', () => cleanup(false));
        }
      });
    });
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  global.showToast = showToast;
  global.confirmAction = confirmAction;
  global.escapeHtml = escapeHtml;
})(window);
