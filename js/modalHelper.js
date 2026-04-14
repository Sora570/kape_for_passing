(function (global) {
  const OVERLAY_CLASS = 'modal-overlay';
  const CONTAINER_CLASS = 'modal-container';

  function ensureStyles() {
    if (document.getElementById('modal-helper-styles')) return;

    const style = document.createElement('style');
    style.id = 'modal-helper-styles';
    style.textContent = `
      .${OVERLAY_CLASS} {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        z-index: 1100;
      }
      .${OVERLAY_CLASS}.show {
        display: flex;
      }
      .${CONTAINER_CLASS} {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 30px 60px rgba(15, 23, 42, 0.25);
        max-height: 90vh;
        overflow-y: auto;
        width: min(520px, 92%);
        display: flex;
        flex-direction: column;
        transform: translateY(20px);
        opacity: 0;
        transition: opacity 160ms ease, transform 160ms ease;
      }
      .${OVERLAY_CLASS}.show .${CONTAINER_CLASS} {
        transform: translateY(0);
        opacity: 1;
      }
      .${CONTAINER_CLASS} header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 22px;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
      }
      .${CONTAINER_CLASS} header h3 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 600;
        color: #1f2937;
      }
      .${CONTAINER_CLASS} header button {
        background: transparent;
        border: none;
        font-size: 1.5rem;
        line-height: 1;
        cursor: pointer;
        color: #6b7280;
      }
      .${CONTAINER_CLASS} header button:hover {
        color: #111827;
      }
      .${CONTAINER_CLASS} .modal-body {
        padding: 22px;
      }
    `;
    document.head.appendChild(style);
  }

  function buildModal({ id, title, content, width }) {
    ensureStyles();

    let overlay = document.getElementById(id);
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = id;
      overlay.className = OVERLAY_CLASS;
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
          ModalHelper.close(id);
        }
      });
      document.body.appendChild(overlay);
    }

    overlay.innerHTML = '';
    overlay.style.display = '';

    const container = document.createElement('div');
    container.className = CONTAINER_CLASS;
    if (width) {
        container.style.width = width;
    }

    if (title) {
      const header = document.createElement('header');
      const h = document.createElement('h3');
      h.textContent = title;
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerHTML = '&times;';
      closeBtn.addEventListener('click', () => ModalHelper.close(id));
      header.appendChild(h);
      header.appendChild(closeBtn);
      container.appendChild(header);
    }

    const body = document.createElement('div');
    body.className = 'modal-body';
    if (typeof content === 'string') {
      body.innerHTML = content;
    } else if (content instanceof HTMLElement) {
      body.appendChild(content);
    }
    container.appendChild(body);

    overlay.appendChild(container);
    requestAnimationFrame(() => overlay.classList.add('show'));

    return { overlay, container, body };
  }

  const ModalHelper = {
    open(options = {}) {
      const { id = `modal-${Date.now()}`, title = '', content = '', width, onOpen } = options;
      const { overlay, container, body } = buildModal({ id, title, content, width });
      overlay.dataset.modalId = id;
      overlay.dataset.open = 'true';

      if (typeof onOpen === 'function') {
        onOpen({ overlay, container, body });
      }

      return { overlay, container, body };
    },

    close(id) {
      const overlay = document.getElementById(id);
      if (!overlay) return;
      overlay.dataset.open = 'false';
      overlay.classList.remove('show');
      setTimeout(() => {
        if (overlay.dataset.open === 'false') {
          overlay.innerHTML = '';
          overlay.style.display = 'none';
        }
      }, 180);
    },

    destroy(id) {
      const overlay = document.getElementById(id);
      if (overlay && overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
    }
  };

  global.ModalHelper = ModalHelper;
})(window);
