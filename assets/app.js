/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import 'bulma/css/bulma.min.css';
import './styles/app.css';
import htmx from 'htmx.org';
import './tour.js';

window.htmx = htmx;
htmx.config.noSwap = [204, 304, '4xx', '5xx'];
htmx.config.transitions = true;

function bindToast($toast) {
    if (!($toast instanceof HTMLElement) || $toast.dataset.toastBound === '1') {
        return;
    }
    $toast.dataset.toastBound = '1';

    const $delete = $toast.querySelector('.delete');
    $delete?.addEventListener('click', () => {
        $toast.remove();
    });

    const delay = parseInt($toast.getAttribute('data-auto-dismiss') || '4500', 10);
    setTimeout(() => {
        $toast.classList.add('is-hiding');
        setTimeout(() => $toast.remove(), 260);
    }, delay);
}

function showToast(type, message) {
    let stack = document.querySelector('.kdo-toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'kdo-toast-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = `notification is-${type} kdo-toast`;
    toast.setAttribute('data-auto-dismiss', '4500');

    const delBtn = document.createElement('button');
    delBtn.className = 'delete';
    delBtn.setAttribute('aria-label', 'delete');

    toast.appendChild(delBtn);
    toast.appendChild(document.createTextNode(message));
    stack.appendChild(toast);
    bindToast(toast);
}

window.kdoShowToast = showToast;

// 1. Top Progress Bar & Button Loading State during HTMX 4 requests
document.addEventListener('htmx:before:request', (e) => {
    const bar = document.getElementById('kdo-progress-bar');
    bar?.classList.add('is-active');

    const elt = e.detail?.ctx?.sourceElement;
    if (elt instanceof HTMLElement) {
        if (elt.classList.contains('button')) {
            elt.classList.add('is-loading');
        } else if (elt.tagName === 'FORM') {
            const submitBtn = elt.querySelector('button[type="submit"], button:not([type])');
            submitBtn?.classList.add('is-loading');
        }
    }
});

document.addEventListener('htmx:after:request', (e) => {
    const rawHeaders = e.detail?.ctx?.response?.headers;

    // Instant theme synchronization from backend response
    const themeHeader = rawHeaders?.get?.('X-Kdo-Theme') || rawHeaders?.get?.('x-kdo-theme');
    if (themeHeader) {
        document.documentElement.setAttribute('data-theme', themeHeader);
    }

    const flashHeader = rawHeaders?.get?.('X-Kdo-Flashes') || rawHeaders?.get?.('x-kdo-flashes');
    if (!flashHeader) {
        return;
    }

    try {
        const flashes = JSON.parse(flashHeader);
        Object.entries(flashes).forEach(([type, messages]) => {
            if (Array.isArray(messages)) {
                messages.forEach((msg) => showToast(type, String(msg)));
            }
        });
    } catch {
        // Ignore malformed header
    }
});

document.addEventListener('htmx:finally:request', (e) => {
    const bar = document.getElementById('kdo-progress-bar');
    bar?.classList.remove('is-active');

    const elt = e.detail?.ctx?.sourceElement;
    if (elt instanceof HTMLElement) {
        if (elt.classList.contains('button')) {
            elt.classList.remove('is-loading');
        } else if (elt.tagName === 'FORM') {
            const submitBtn = elt.querySelector('button[type="submit"], button:not([type])');
            submitBtn?.classList.remove('is-loading');
        }
    }
});

// 2. Dynamic HTMX Modal (opens automatically when content settles into #kdo-dynamic-modal-body)
document.addEventListener('htmx:after:settle', (e) => {
    const target = e.target;
    if (target instanceof HTMLElement && (target.id === 'kdo-dynamic-modal-body' || target.closest('#kdo-dynamic-modal-body'))) {
        const modal = document.getElementById('kdo-dynamic-modal');
        modal?.classList.add('is-active');
        const firstInput = target.querySelector('input:not([type="hidden"]), select, textarea');
        if (firstInput instanceof HTMLElement) {
            firstInput.focus();
        }
    }
});

document.addEventListener('click', (e) => {
    const target = e.target;
    if (!(target instanceof Element)) {
        return;
    }

    const closeBtn = target.closest('[data-modal-close]');
    if (closeBtn) {
        e.preventDefault();
        const modal = closeBtn.closest('.modal') || document.getElementById('kdo-dynamic-modal');
        modal?.classList.remove('is-active');
        return;
    }

    const themeBtn = target.closest('#kdo-theme-toggle');
    if (themeBtn) {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', nextTheme);
    }
});

function closeMobileMenu() {
    document.querySelectorAll('.navbar-burger.is-active').forEach((el) => {
        el.classList.remove('is-active');
        el.setAttribute('aria-expanded', 'false');
    });
    document.querySelectorAll('.navbar-menu.is-active').forEach((menu) => {
        menu.classList.remove('is-active');
    });
    document.documentElement.classList.remove('is-clipped-mobile');
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.is-active').forEach((modal) => {
            modal.classList.remove('is-active');
        });
        closeMobileMenu();
    }
});

// 3. Styled HTMX 4 Confirmation Modal (replaces native window.confirm)
document.addEventListener('htmx:confirm', (e) => {
    const question = e.detail?.ctx?.confirm || e.detail?.question;
    if (!question) {
        return;
    }

    const modal = document.getElementById('kdo-confirm-modal');
    const msgEl = document.getElementById('kdo-confirm-message');
    const okBtn = document.getElementById('kdo-confirm-ok');

    if (!modal || !msgEl || !okBtn) {
        return;
    }

    e.preventDefault();
    msgEl.textContent = question;
    modal.classList.add('is-active');

    const close = () => {
        modal.classList.remove('is-active');
    };

    const onConfirm = () => {
        cleanup();
        e.detail.issueRequest?.(true);
    };

    const onCancel = () => {
        cleanup();
        e.detail.dropRequest?.();
    };

    const onKeyDown = (evt) => {
        if (evt.key === 'Escape') {
            onCancel();
        }
    };

    const cancelButtons = modal.querySelectorAll('[data-confirm-cancel]');

    const cleanup = () => {
        close();
        okBtn.removeEventListener('click', onConfirm);
        cancelButtons.forEach((btn) => btn.removeEventListener('click', onCancel));
        document.removeEventListener('keydown', onKeyDown);
    };

    okBtn.addEventListener('click', onConfirm);
    cancelButtons.forEach((btn) => btn.addEventListener('click', onCancel));
    document.addEventListener('keydown', onKeyDown);
    okBtn.focus();
});

// 4. Component initialization on page & HTMX load
function initContent(content) {
    if (!content?.querySelectorAll) {
        return;
    }

    const $navbarBurgers = content.querySelectorAll('.navbar-burger');
    $navbarBurgers.forEach(el => {
        if (el.dataset.burgerBound === '1') {
            return;
        }
        el.dataset.burgerBound = '1';

        el.addEventListener('click', () => {
            const target = el.dataset.target;
            const $target = document.getElementById(target);

            if ($target) {
                const isActive = !el.classList.contains('is-active');
                el.classList.toggle('is-active', isActive);
                $target.classList.toggle('is-active', isActive);
                el.setAttribute('aria-expanded', String(isActive));
                document.documentElement.classList.toggle('is-clipped-mobile', isActive);
            }
        });
    });

    const $navbarMenu = content.querySelector('#navbarMain');
    if ($navbarMenu && $navbarMenu.dataset.menuCloseBound !== '1') {
        $navbarMenu.dataset.menuCloseBound = '1';
        $navbarMenu.addEventListener('click', (e) => {
            const target = e.target;
            if (!(target instanceof Element)) {
                return;
            }
            const item = target.closest('.navbar-item:not(.has-dropdown), [data-tour-start]');
            if (item) {
                closeMobileMenu();
            }
        });
    }

    const $notificationDeletes = content.querySelectorAll('.notification .delete');
    $notificationDeletes.forEach($delete => {
        if ($delete.dataset.deleteBound === '1') {
            return;
        }
        $delete.dataset.deleteBound = '1';

        $delete.addEventListener('click', () => {
            $delete.parentElement?.remove();
        });
    });

    const $toasts = content.querySelectorAll('.kdo-toast[data-auto-dismiss]');
    $toasts.forEach(bindToast);
}

initContent(document);
htmx.onLoad(initContent);
