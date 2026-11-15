/**
 * KDO Interactive Guided Tour (EnjoyHint-style spotlight + curved SVG arrow)
 */

const STORAGE_ACTIVE_KEY = 'kdo_tour_active';
const STORAGE_STEP_KEY = 'kdo_tour_step';
const SVG_NS = 'http://www.w3.org/2000/svg';

function getTourConfig() {
    const configEl = document.getElementById('kdo-tour-config');
    if (!configEl) {
        return null;
    }

    try {
        return JSON.parse(configEl.textContent || '{}');
    } catch {
        return null;
    }
}

function buildSteps(routes) {
    return [
        {
            path: routes.home,
            pageLabel: 'Tableau de bord',
            selector: '[data-tour="dashboard-kpis"]',
            title: 'Votre tableau de bord',
            description: 'Retrouvez ici en un coup d\'œil vos souhaits actifs, vos projets en cours, votre bilan financier et vos contacts.',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.home,
            pageLabel: 'Tableau de bord',
            selector: '[data-tour="dashboard-quick-actions"]',
            title: 'Actions rapides',
            description: 'Ajoutez un nouveau souhait ou lancez un projet cadeau collectif directement depuis ces boutons.',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.home,
            pageLabel: 'Navigation',
            selector: '[data-tour="navbar-notifications"]',
            title: 'Notifications en direct',
            description: 'Soyez alerté dès qu\'un proche vous invite, qu\'une idée est ajoutée ou qu\'un remboursement est effectué.',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.wish,
            pageLabel: 'Ma Liste',
            selector: '[data-tour="wish-tabs"]',
            title: 'Votre liste & celles de vos proches',
            description: 'Gérez votre propre liste de souhaits et créez des listes pour vos enfants ou proches sans compte grâce à l\'onglet « Ajouter un proche ».',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.wish,
            pageLabel: 'Ma Liste',
            selector: '[data-tour="wish-add-btn"]',
            title: 'Ajouter un souhait',
            description: 'Collez simplement l\'URL d\'un produit : KDO remplit automatiquement le nom, la photo et le prix ! Vos proches réservent en secret sans vous spoiler.',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.contact,
            pageLabel: 'Contacts',
            selector: '[data-tour="contact-invite"]',
            title: 'Inviter un proche',
            description: 'Entrez l\'adresse email d\'un ami ou membre de la famille pour partager mutuellement vos listes de souhaits.',
            preferredPlacement: 'left',
        },
        {
            path: routes.contact,
            pageLabel: 'Contacts',
            selector: '[data-tour="contact-list"]',
            title: 'Listes & soldes de vos contacts',
            description: 'Consultez les listes de vos contacts (et de leurs proches gérés), réservez en secret ou créez un projet commun depuis un souhait, et suivez votre balance.',
            preferredPlacement: 'right',
        },
        {
            path: routes.project,
            pageLabel: 'Projets Cadeaux',
            selector: '[data-tour="project-new-btn"]',
            title: 'Organiser un cadeau commun',
            description: 'Créez un projet secret pour un proche : proposez des idées, votez, discutez, lancez une cagnotte et répartissez les parts sans spoiler le destinataire.',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.project,
            pageLabel: 'Projets Cadeaux',
            selector: '[data-tour="project-archives-btn"]',
            title: 'Projets archivés',
            description: 'Une fois le cadeau offert et les comptes réglés, archivez vos anciens projets pour garder votre espace bien rangé.',
            preferredPlacement: 'bottom',
        },
        {
            path: routes.financial,
            pageLabel: 'Finances',
            selector: '[data-tour="financial-summary"]',
            title: 'Bilan financier & Remboursements',
            description: 'Visualisez exactement qui doit combien à qui sur l\'ensemble de vos projets cadeaux et marquez les remboursements effectués en un clic.',
            preferredPlacement: 'bottom',
        },
    ];
}

function normalizePath(path) {
    if (!path || path === '/') {
        return '/';
    }
    return path.endsWith('/') ? path.slice(0, -1) : path;
}

class KdoTour {
    constructor() {
        this.overlay = null;
        this.svg = null;
        this.maskHole = null;
        this.navMaskHole = null;
        this.spotlightRing = null;
        this.navRing = null;
        this.arrowPath = null;
        this.tooltip = null;
        this.currentStepIndex = 0;
        this.steps = [];
        this.config = null;
        this.rafId = null;

        this.onResizeOrScroll = this.schedulePositionUpdate.bind(this);
        this.onKeyDown = this.handleKeyDown.bind(this);
    }

    init() {
        this.config = getTourConfig();
        if (!this.config || !this.config.routes) {
            return;
        }

        this.steps = buildSteps(this.config.routes);

        if (sessionStorage.getItem(STORAGE_ACTIVE_KEY) === '1') {
            const savedStep = parseInt(sessionStorage.getItem(STORAGE_STEP_KEY) || '0', 10);
            const idx = Number.isNaN(savedStep) ? 0 : Math.max(0, Math.min(this.steps.length - 1, savedStep));
            this.goToStep(idx);
        }
    }

    start(stepIndex = 0) {
        this.config = getTourConfig();
        if (!this.config || !this.config.routes) {
            return;
        }
        this.steps = buildSteps(this.config.routes);
        sessionStorage.setItem(STORAGE_ACTIVE_KEY, '1');
        this.goToStep(stepIndex);
    }

    stop(clearStorage = true) {
        if (clearStorage) {
            sessionStorage.removeItem(STORAGE_ACTIVE_KEY);
            sessionStorage.removeItem(STORAGE_STEP_KEY);
        }

        window.removeEventListener('resize', this.onResizeOrScroll);
        window.removeEventListener('scroll', this.onResizeOrScroll, true);
        document.removeEventListener('keydown', this.onKeyDown);

        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }

        this.overlay?.remove();
        this.tooltip?.remove();
        this.overlay = null;
        this.tooltip = null;
    }

    async finish() {
        this.stop(true);
        document.getElementById('kdo-tour-banner')?.remove();

        if (this.config?.completeUrl) {
            try {
                await fetch(this.config.completeUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
            } catch {
                // Non-blocking
            }
        }

        if (typeof window.kdoShowToast === 'function') {
            window.kdoShowToast('success', 'Visite guidée terminée ! Vous pouvez la relancer à tout moment depuis le menu.');
        }
    }

    goToStep(index) {
        if (index < 0 || index >= this.steps.length) {
            this.stop(true);
            return;
        }

        this.currentStepIndex = index;
        sessionStorage.setItem(STORAGE_STEP_KEY, String(index));

        const step = this.steps[index];
        const currentPath = normalizePath(window.location.pathname);
        const targetPath = normalizePath(step.path);

        if (currentPath !== targetPath) {
            this.stop(false);
            window.location.href = step.path;
            return;
        }

        this.renderStep(step);
    }

    ensureElements() {
        if (this.overlay && this.tooltip) {
            return;
        }

        // Overlay + SVG
        const overlay = document.createElement('div');
        overlay.id = 'kdo-tour-overlay';
        overlay.className = 'kdo-tour-overlay';

        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'kdo-tour-svg');
        svg.setAttribute('width', '100%');
        svg.setAttribute('height', '100%');

        const defs = document.createElementNS(SVG_NS, 'defs');

        const mask = document.createElementNS(SVG_NS, 'mask');
        mask.setAttribute('id', 'kdo-tour-mask');

        const maskBg = document.createElementNS(SVG_NS, 'rect');
        maskBg.setAttribute('x', '0');
        maskBg.setAttribute('y', '0');
        maskBg.setAttribute('width', '100%');
        maskBg.setAttribute('height', '100%');
        maskBg.setAttribute('fill', 'white');

        const maskHole = document.createElementNS(SVG_NS, 'rect');
        maskHole.setAttribute('rx', '12');
        maskHole.setAttribute('ry', '12');
        maskHole.setAttribute('fill', 'black');

        const navMaskHole = document.createElementNS(SVG_NS, 'rect');
        navMaskHole.setAttribute('rx', '8');
        navMaskHole.setAttribute('ry', '8');
        navMaskHole.setAttribute('fill', 'black');

        mask.appendChild(maskBg);
        mask.appendChild(maskHole);
        mask.appendChild(navMaskHole);

        const marker = document.createElementNS(SVG_NS, 'marker');
        marker.setAttribute('id', 'kdo-tour-arrowhead');
        marker.setAttribute('markerWidth', '12');
        marker.setAttribute('markerHeight', '12');
        marker.setAttribute('refX', '9');
        marker.setAttribute('refY', '6');
        marker.setAttribute('orient', 'auto');
        marker.setAttribute('markerUnits', 'userSpaceOnUse');

        const arrowHeadPath = document.createElementNS(SVG_NS, 'path');
        arrowHeadPath.setAttribute('d', 'M 1 1.5 L 10 6 L 1 10.5');
        arrowHeadPath.setAttribute('fill', 'none');
        arrowHeadPath.setAttribute('stroke', '#ffffff');
        arrowHeadPath.setAttribute('stroke-width', '2.4');
        arrowHeadPath.setAttribute('stroke-linecap', 'round');
        arrowHeadPath.setAttribute('stroke-linejoin', 'round');
        marker.appendChild(arrowHeadPath);

        defs.appendChild(mask);
        defs.appendChild(marker);
        svg.appendChild(defs);

        const backdrop = document.createElementNS(SVG_NS, 'rect');
        backdrop.setAttribute('x', '0');
        backdrop.setAttribute('y', '0');
        backdrop.setAttribute('width', '100%');
        backdrop.setAttribute('height', '100%');
        backdrop.setAttribute('fill', 'rgba(10, 14, 24, 0.74)');
        backdrop.setAttribute('mask', 'url(#kdo-tour-mask)');

        const spotlightRing = document.createElementNS(SVG_NS, 'rect');
        spotlightRing.setAttribute('class', 'kdo-tour-spotlight-ring');
        spotlightRing.setAttribute('rx', '12');
        spotlightRing.setAttribute('ry', '12');
        spotlightRing.setAttribute('fill', 'none');

        const navRing = document.createElementNS(SVG_NS, 'rect');
        navRing.setAttribute('class', 'kdo-tour-nav-ring');
        navRing.setAttribute('rx', '8');
        navRing.setAttribute('ry', '8');
        navRing.setAttribute('fill', 'none');

        const arrowPath = document.createElementNS(SVG_NS, 'path');
        arrowPath.setAttribute('class', 'kdo-tour-arrow-path');
        arrowPath.setAttribute('fill', 'none');
        arrowPath.setAttribute('marker-end', 'url(#kdo-tour-arrowhead)');

        svg.appendChild(backdrop);
        svg.appendChild(navRing);
        svg.appendChild(spotlightRing);
        svg.appendChild(arrowPath);
        overlay.appendChild(svg);

        // Tooltip Card
        const tooltip = document.createElement('div');
        tooltip.id = 'kdo-tour-tooltip';
        tooltip.className = 'kdo-tour-tooltip box p-4';
        tooltip.setAttribute('role', 'dialog');
        tooltip.setAttribute('aria-modal', 'true');

        document.body.appendChild(overlay);
        document.body.appendChild(tooltip);

        this.overlay = overlay;
        this.svg = svg;
        this.maskHole = maskHole;
        this.navMaskHole = navMaskHole;
        this.spotlightRing = spotlightRing;
        this.navRing = navRing;
        this.arrowPath = arrowPath;
        this.tooltip = tooltip;

        window.addEventListener('resize', this.onResizeOrScroll);
        window.addEventListener('scroll', this.onResizeOrScroll, true);
        document.addEventListener('keydown', this.onKeyDown);
    }

    renderStep(step) {
        this.ensureElements();

        const isFirst = this.currentStepIndex === 0;
        const isLast = this.currentStepIndex === this.steps.length - 1;
        const stepNum = this.currentStepIndex + 1;
        const total = this.steps.length;

        this.tooltip.innerHTML = `
            <div class="is-flex is-justify-content-space-between is-align-items-center mb-2">
                <div class="is-flex is-align-items-center" style="gap: 0.4rem;">
                    <span class="tag is-primary is-light is-rounded has-text-weight-semibold">Étape ${stepNum} / ${total}</span>
                    <span class="tag is-light is-rounded">${step.pageLabel}</span>
                </div>
                <button type="button" class="delete is-small" aria-label="Fermer la visite" data-tour-action="close"></button>
            </div>
            <h3 class="title is-5 mb-2">${step.title}</h3>
            <p class="is-size-6 has-text-grey mb-4">${step.description}</p>
            <div class="is-flex is-justify-content-space-between is-align-items-center">
                <button type="button" class="button is-ghost is-small px-0 has-text-grey" data-tour-action="close">
                    Passer
                </button>
                <div class="buttons are-small mb-0">
                    ${!isFirst ? '<button type="button" class="button is-light" data-tour-action="prev">Précédent</button>' : ''}
                    ${isLast
                        ? '<button type="button" class="button is-success" data-tour-action="finish">Terminer</button>'
                        : '<button type="button" class="button is-primary" data-tour-action="next">Suivant</button>'
                    }
                </div>
            </div>
        `;

        this.tooltip.querySelectorAll('[data-tour-action]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const action = e.currentTarget.getAttribute('data-tour-action');
                if (action === 'next') {
                    this.goToStep(this.currentStepIndex + 1);
                } else if (action === 'prev') {
                    this.goToStep(this.currentStepIndex - 1);
                } else if (action === 'finish') {
                    this.finish();
                } else if (action === 'close') {
                    this.stop(true);
                }
            });
        });

        const target = document.querySelector(step.selector);
        if (target instanceof HTMLElement) {
            const rect = target.getBoundingClientRect();
            const inView = rect.top >= 70 && rect.bottom <= window.innerHeight - 40;
            if (!inView) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            }
        }

        this.updatePositions();
        // Re-measure after smooth scroll settles
        setTimeout(() => this.updatePositions(), 220);
        setTimeout(() => this.updatePositions(), 450);
    }

    schedulePositionUpdate() {
        if (this.rafId) {
            return;
        }
        this.rafId = requestAnimationFrame(() => {
            this.rafId = null;
            this.updatePositions();
        });
    }

    updatePositions() {
        if (!this.overlay || !this.tooltip) {
            return;
        }

        const step = this.steps[this.currentStepIndex];
        const target = step ? document.querySelector(step.selector) : null;

        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const margin = 16;

        // Highlight active global navbar tab so user always sees which section they are on
        const activeNav = document.querySelector('.navbar .navbar-item.is-active');
        if (activeNav instanceof HTMLElement && activeNav.offsetParent !== null && activeNav !== target) {
            const nr = activeNav.getBoundingClientRect();
            if (nr.width > 0 && nr.height > 0) {
                const npad = 4;
                const nx = Math.max(2, nr.left - npad);
                const ny = Math.max(2, nr.top - npad);
                const nw = nr.width + npad * 2;
                const nh = nr.height + npad * 2;

                this.navMaskHole.setAttribute('x', String(nx));
                this.navMaskHole.setAttribute('y', String(ny));
                this.navMaskHole.setAttribute('width', String(nw));
                this.navMaskHole.setAttribute('height', String(nh));

                this.navRing.setAttribute('x', String(nx));
                this.navRing.setAttribute('y', String(ny));
                this.navRing.setAttribute('width', String(nw));
                this.navRing.setAttribute('height', String(nh));
            } else {
                this.navMaskHole.setAttribute('width', '0');
                this.navMaskHole.setAttribute('height', '0');
                this.navRing.setAttribute('width', '0');
                this.navRing.setAttribute('height', '0');
            }
        } else {
            this.navMaskHole.setAttribute('width', '0');
            this.navMaskHole.setAttribute('height', '0');
            this.navRing.setAttribute('width', '0');
            this.navRing.setAttribute('height', '0');
        }

        // Measure tooltip
        const ttRect = this.tooltip.getBoundingClientRect();
        const ttW = ttRect.width || Math.min(360, vw - 32);
        const ttH = ttRect.height || 200;

        if (!(target instanceof HTMLElement) || target.offsetParent === null && getComputedStyle(target).position !== 'fixed') {
            // Fallback: center tooltip, no spotlight, no arrow
            this.maskHole.setAttribute('width', '0');
            this.maskHole.setAttribute('height', '0');
            this.spotlightRing.setAttribute('width', '0');
            this.spotlightRing.setAttribute('height', '0');
            this.arrowPath.setAttribute('d', '');

            this.tooltip.style.left = `${Math.max(margin, (vw - ttW) / 2)}px`;
            this.tooltip.style.top = `${Math.max(margin, (vh - ttH) / 2)}px`;
            return;
        }

        const raw = target.getBoundingClientRect();
        const pad = 8;
        const spot = {
            left: Math.max(4, raw.left - pad),
            top: Math.max(4, raw.top - pad),
            right: Math.min(vw - 4, raw.right + pad),
            bottom: Math.min(vh - 4, raw.bottom + pad),
        };
        spot.width = Math.max(0, spot.right - spot.left);
        spot.height = Math.max(0, spot.bottom - spot.top);
        spot.cx = spot.left + spot.width / 2;
        spot.cy = spot.top + spot.height / 2;

        this.maskHole.setAttribute('x', String(spot.left));
        this.maskHole.setAttribute('y', String(spot.top));
        this.maskHole.setAttribute('width', String(spot.width));
        this.maskHole.setAttribute('height', String(spot.height));

        this.spotlightRing.setAttribute('x', String(spot.left));
        this.spotlightRing.setAttribute('y', String(spot.top));
        this.spotlightRing.setAttribute('width', String(spot.width));
        this.spotlightRing.setAttribute('height', String(spot.height));

        // Choose placement
        const gap = vw < 640 ? 28 : 52; // Room for curved arrow
        const spaceBelow = vh - spot.bottom - margin;
        const spaceAbove = spot.top - margin;
        const spaceRight = vw - spot.right - margin;
        const spaceLeft = spot.left - margin;

        let placement = step.preferredPlacement || 'bottom';
        const fits = {
            bottom: spaceBelow >= ttH + gap,
            top: spaceAbove >= ttH + gap,
            right: spaceRight >= ttW + gap,
            left: spaceLeft >= ttW + gap,
        };

        if (!fits[placement]) {
            const order = ['bottom', 'top', 'right', 'left'];
            placement = order.find((p) => fits[p]) || (spaceBelow >= spaceAbove ? 'bottom' : 'top');
        }

        let ttLeft = 0;
        let ttTop = 0;

        if (placement === 'bottom') {
            ttLeft = spot.cx - ttW / 2;
            ttTop = spot.bottom + gap;
        } else if (placement === 'top') {
            ttLeft = spot.cx - ttW / 2;
            ttTop = spot.top - gap - ttH;
        } else if (placement === 'right') {
            ttLeft = spot.right + gap;
            ttTop = spot.cy - ttH / 2;
        } else {
            ttLeft = spot.left - gap - ttW;
            ttTop = spot.cy - ttH / 2;
        }

        // Clamp within viewport
        ttLeft = Math.max(margin, Math.min(vw - ttW - margin, ttLeft));
        ttTop = Math.max(margin, Math.min(vh - ttH - margin, ttTop));

        this.tooltip.style.left = `${ttLeft}px`;
        this.tooltip.style.top = `${ttTop}px`;

        // Draw curved EnjoyHint-style SVG arrow from tooltip to spotlight
        const ttBox = {
            left: ttLeft,
            top: ttTop,
            right: ttLeft + ttW,
            bottom: ttTop + ttH,
            cx: ttLeft + ttW / 2,
            cy: ttTop + ttH / 2,
        };

        let sx;
        let sy;
        let ex;
        let ey;

        if (placement === 'bottom') {
            sx = Math.max(ttBox.left + 24, Math.min(ttBox.right - 24, spot.cx));
            sy = ttBox.top - 6;
            ex = spot.cx;
            ey = spot.bottom + 8;
        } else if (placement === 'top') {
            sx = Math.max(ttBox.left + 24, Math.min(ttBox.right - 24, spot.cx));
            sy = ttBox.bottom + 6;
            ex = spot.cx;
            ey = spot.top - 8;
        } else if (placement === 'right') {
            sx = ttBox.left - 6;
            sy = Math.max(ttBox.top + 24, Math.min(ttBox.bottom - 24, spot.cy));
            ex = spot.right + 8;
            ey = spot.cy;
        } else {
            sx = ttBox.right + 6;
            sy = Math.max(ttBox.top + 24, Math.min(ttBox.bottom - 24, spot.cy));
            ex = spot.left - 8;
            ey = spot.cy;
        }

        const dx = ex - sx;
        const dy = ey - sy;
        const dist = Math.hypot(dx, dy);

        if (dist < 16) {
            this.arrowPath.setAttribute('d', '');
            return;
        }

        // Perpendicular curve offset for a graceful hand-drawn EnjoyHint arc
        const mx = (sx + ex) / 2;
        const my = (sy + ey) / 2;
        const curveAmount = Math.min(42, Math.max(18, dist * 0.35));
        const nx = -dy / dist;
        const ny = dx / dist;
        const cx = mx + nx * curveAmount;
        const cy = my + ny * curveAmount;

        this.arrowPath.setAttribute('d', `M ${sx.toFixed(1)} ${sy.toFixed(1)} Q ${cx.toFixed(1)} ${cy.toFixed(1)} ${ex.toFixed(1)} ${ey.toFixed(1)}`);
    }

    handleKeyDown(e) {
        if (!this.overlay) {
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            this.stop(true);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            if (this.currentStepIndex < this.steps.length - 1) {
                this.goToStep(this.currentStepIndex + 1);
            } else {
                this.finish();
            }
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            if (this.currentStepIndex > 0) {
                this.goToStep(this.currentStepIndex - 1);
            }
        }
    }
}

const tour = new KdoTour();

document.addEventListener('click', (e) => {
    const target = e.target;
    if (!(target instanceof Element)) {
        return;
    }

    const startBtn = target.closest('[data-tour-start]');
    if (startBtn) {
        e.preventDefault();
        const stepAttr = startBtn.getAttribute('data-tour-start-step');
        const stepIdx = stepAttr ? parseInt(stepAttr, 10) : 0;
        tour.start(Number.isNaN(stepIdx) ? 0 : stepIdx);
    }
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => tour.init());
} else {
    tour.init();
}

document.addEventListener('htmx:after:settle', () => {
    if (sessionStorage.getItem(STORAGE_ACTIVE_KEY) === '1' && !document.getElementById('kdo-tour-overlay')) {
        tour.init();
    }
});
