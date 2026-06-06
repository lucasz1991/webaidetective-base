const instances = new WeakMap();
const activeRoots = new Set();
let cytoscapeLoader;

function loadCytoscape() {
    cytoscapeLoader ??= import('cytoscape').then((module) => module.default || module);

    return cytoscapeLoader;
}

function truncate(value, length = 28) {
    const text = String(value || '').trim();

    return text.length > length ? `${text.slice(0, length - 1)}...` : text;
}

function initialFor(value) {
    const text = String(value || '').replace(/^@/, '').trim();

    return (text.charAt(0) || '?').toUpperCase();
}

function visibilityValue(data) {
    const visibility = String(data?.profileVisibility || data?.visibility || '').toLowerCase();

    if (visibility === 'public' || visibility === 'private') {
        return visibility;
    }

    const status = String(data?.status || '').toLowerCase();

    if (status === 'public' || status === 'private') {
        return status;
    }

    return 'unknown';
}

function visibilityLabel(data) {
    const visibility = visibilityValue(data);

    if (visibility === 'public') {
        return 'Oeffentlich';
    }

    if (visibility === 'private') {
        return 'Privat';
    }

    return 'Unbekannt';
}

function visibilityBadgeElement(data) {
    const badge = document.createElement('span');
    const visibility = visibilityValue(data);
    const classes = {
        public: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        private: 'bg-slate-100 text-slate-700 ring-slate-200',
        unknown: 'bg-amber-50 text-amber-800 ring-amber-200',
    };

    badge.className = `inline-flex rounded-lg px-2 py-0.5 text-[11px] font-semibold ring-1 ${classes[visibility] || classes.unknown}`;
    badge.textContent = visibilityLabel(data);

    return badge;
}

function avatarElement(data, large = false) {
    const imageUrl = String(data?.imageUrl || '').trim();
    const element = document.createElement(imageUrl ? 'img' : 'div');
    const sizeClass = large ? 'h-12 w-12' : 'h-9 w-9';
    const commonClasses = `${sizeClass} shrink-0 rounded-full border border-slate-200`;

    if (imageUrl) {
        element.src = imageUrl;
        element.alt = data?.handle || data?.fullLabel || 'Instagram-Profilbild';
        element.loading = 'lazy';
        element.referrerPolicy = 'no-referrer';
        element.className = `${commonClasses} object-cover bg-slate-100`;

        return element;
    }

    element.className = `${commonClasses} flex items-center justify-center bg-slate-100 text-xs font-bold text-slate-600`;
    element.textContent = initialFor(data?.handle || data?.fullLabel || data?.label);

    return element;
}

function readGraph(root) {
    const payload = root.querySelector('[data-network-map-payload]');

    if (!payload) {
        return { nodes: [], edges: [] };
    }

    try {
        return JSON.parse(payload.textContent || '{}');
    } catch (error) {
        console.error('Netzwerkdaten konnten nicht gelesen werden.', error);
        return { nodes: [], edges: [] };
    }
}

function eventDetail(event) {
    return Array.isArray(event.detail) ? (event.detail[0] || {}) : (event.detail || {});
}

function updateBuildStatus(root, options = {}) {
    const panel = root.querySelector('[data-network-loading-panel]');
    const label = root.querySelector('[data-network-build-label]');
    const text = root.querySelector('[data-network-build-text]');
    const count = root.querySelector('[data-network-progress-count]');
    const bar = root.querySelector('[data-network-progress-bar]');
    const dot = root.querySelector('[data-network-build-dot]');

    if (!panel) {
        return;
    }

    panel.classList.toggle('hidden', options.visible === false);

    if (label && options.label) {
        label.textContent = options.label;
    }

    if (text && options.text) {
        text.textContent = options.text;
    }

    if (count && options.count) {
        count.textContent = options.count;
    }

    if (bar) {
        const progress = Number.isFinite(Number(options.progress)) ? Math.max(0, Math.min(100, Number(options.progress))) : 0;
        bar.style.width = `${progress}%`;
    }

    if (dot) {
        dot.classList.toggle('bg-sky-500', options.state !== 'done' && options.state !== 'error');
        dot.classList.toggle('bg-emerald-500', options.state === 'done');
        dot.classList.toggle('bg-rose-500', options.state === 'error');
    }
}

function publicBadgeLayer(root) {
    return root.querySelector('[data-network-public-badges]');
}

function updatePublicBadges(root, cy) {
    const layer = publicBadgeLayer(root);
    const container = cy?.container?.();

    if (!layer || !container) {
        return;
    }

    const width = cy.width();
    const height = cy.height();
    const fragment = document.createDocumentFragment();

    cy.nodes('.network-profile-public')
        .not('.network-filtered')
        .filter((node) => node.data('type') !== 'person' && Boolean(node.data('hasImage')))
        .forEach((node) => {
            const position = node.renderedPosition();
            const nodeSize = Number(node.renderedWidth?.() || node.data('nodeSize') || 42);
            const badgeSize = Math.max(16, Math.min(22, Math.round(nodeSize * 0.34)));
            const left = position.x + (nodeSize / 2) - (badgeSize * 0.25);
            const top = position.y - (nodeSize / 2) + (badgeSize * 0.25);

            if (left < -badgeSize || top < -badgeSize || left > width + badgeSize || top > height + badgeSize) {
                return;
            }

            const badge = document.createElement('span');
            badge.className = 'network-public-profile-badge';
            badge.textContent = '\u2713';
            badge.title = 'Oeffentlich erkannt';
            badge.style.cssText = [
                'position:absolute',
                `left:${left}px`,
                `top:${top}px`,
                `width:${badgeSize}px`,
                `height:${badgeSize}px`,
                'transform:translate(-50%,-50%)',
                'display:flex',
                'align-items:center',
                'justify-content:center',
                'border-radius:9999px',
                'border:2px solid #ffffff',
                'background:#10b981',
                'color:#ffffff',
                'font-size:11px',
                'font-weight:900',
                'line-height:1',
                'box-shadow:0 6px 14px rgba(15,23,42,0.22)',
            ].join(';');
            fragment.append(badge);
        });

    layer.replaceChildren(fragment);
}

function schedulePublicBadgeUpdate(root, cy) {
    const state = instances.get(root);

    if (!state || state.publicBadgeUpdateQueued) {
        return;
    }

    state.publicBadgeUpdateQueued = true;
    window.requestAnimationFrame(() => {
        state.publicBadgeUpdateQueued = false;
        updatePublicBadges(root, cy);
    });
}

function bindPublicBadges(root, cy) {
    const update = () => schedulePublicBadgeUpdate(root, cy);

    cy.on('pan zoom resize render position add remove data style', update);
    update();
}

function schedulePublicBadgeUpdateFromCy(cy) {
    activeRoots.forEach((root) => {
        const state = instances.get(root);

        if (state?.cy === cy) {
            schedulePublicBadgeUpdate(root, cy);
        }
    });
}

function toElements(graph) {
    const nodes = (graph.nodes || []).map((node) => ({
        group: 'nodes',
        classes: [
            node.hasImage ? 'network-has-image' : '',
            node.isPrimary ? 'network-primary' : '',
            visibilityValue(node) === 'public' ? 'network-profile-public' : '',
            visibilityValue(node) === 'private' ? 'network-profile-private' : '',
        ].filter(Boolean).join(' '),
        position: Number.isFinite(Number(node.x)) && Number.isFinite(Number(node.y))
            ? { x: Number(node.x), y: Number(node.y) }
            : undefined,
        locked: Boolean(node.isPrimary),
        data: {
            ...node,
            label: truncate(node.label || node.handle || node.id),
            fullLabel: node.label || node.handle || node.id,
            handle: node.handle || '',
            detail: node.detail || '',
            role: node.role || '',
        },
    }));

    const edges = (graph.edges || []).map((edge) => ({
        group: 'edges',
        data: {
            ...edge,
            source: edge.from,
            target: edge.to,
            networkType: edge.type,
            label: edge.label || (edge.type === 'inferred' ? 'rekonstruiert' : 'Profil'),
        },
    }));

    return [...nodes, ...edges];
}

function updateButton(button, active) {
    const activeClasses = (button.dataset.activeClasses || '').split(' ').filter(Boolean);
    const inactiveClasses = (button.dataset.inactiveClasses || '').split(' ').filter(Boolean);

    button.setAttribute('aria-pressed', active ? 'true' : 'false');
    button.classList.remove(...activeClasses, ...inactiveClasses);
    button.classList.add(...(active ? activeClasses : inactiveClasses));
}

function filterStorageKey(root) {
    return `network-map-filters:${root.dataset.networkFilterScope || root.dataset.networkMapId || 'default'}`;
}

function readStoredFilters(root) {
    try {
        return JSON.parse(sessionStorage.getItem(filterStorageKey(root)) || '{}') || {};
    } catch {
        return {};
    }
}

function writeStoredFilters(root, state) {
    sessionStorage.setItem(filterStorageKey(root), JSON.stringify({
        showPublic: state.showPublic,
        showInferred: state.showInferred,
        showTracked: state.showTracked,
        showDirectOnly: state.showDirectOnly,
        minDegree: state.minDegree,
        maxVisibleProfiles: state.maxVisibleProfiles,
    }));
}

function visibleConnectedEdges(node) {
    return node.connectedEdges().not('.network-filtered');
}

function visibleDegree(node) {
    return visibleConnectedEdges(node).length;
}

function edgeVisibleForState(edge, state) {
    const type = edge.data('networkType');

    if (type === 'public-profile') {
        return state.showPublic;
    }

    if (type === 'inferred') {
        return state.showInferred;
    }

    if (type === 'tracked-list' || type === 'tracked-profile-rel') {
        return state.showTracked;
    }

    return true;
}

function visibleDegreeForState(node, state) {
    return node.connectedEdges().filter((edge) => edgeVisibleForState(edge, state)).length;
}

function directReferenceNode(cy, state) {
    if (state?.selectedId) {
        const selected = cy.getElementById(state.selectedId);

        if (selected.length) {
            return selected;
        }
    }

    const primary = cy.nodes().filter((node) => Boolean(node.data('isPrimary'))).first();

    if (primary.length) {
        return primary;
    }

    const person = cy.nodes('[type = "person"]').first();

    return person.length ? person : cy.nodes().first();
}

function directVisibleNodeIds(cy, state) {
    const reference = directReferenceNode(cy, state);
    const ids = new Set();

    if (!reference?.length) {
        return ids;
    }

    ids.add(reference.id());
    reference.connectedEdges()
        .filter((edge) => edgeVisibleForState(edge, state))
        .connectedNodes()
        .forEach((node) => ids.add(node.id()));

    return ids;
}

function networkMaxVisibleProfiles(root) {
    const limit = Number(root.dataset.networkMaxVisibleProfiles || 100);

    return Number.isFinite(limit) && limit > 0 ? Math.floor(limit) : 100;
}

function selectedMaxVisibleProfiles(root) {
    const control = root.querySelector('[data-network-filter-max-profiles]');

    if (!control) {
        return networkMaxVisibleProfiles(root);
    }

    const value = Number(control.value || root.dataset.networkMaxVisibleProfiles || 100);

    if (!Number.isFinite(value)) {
        return networkMaxVisibleProfiles(root);
    }

    return Math.max(0, Math.floor(value));
}

function ensureMaxProfilesOption(root, value) {
    const control = root.querySelector('[data-network-filter-max-profiles]');

    if (!control) {
        return;
    }

    const normalized = String(Math.max(0, Math.floor(Number(value) || 0)));
    const exists = Array.from(control.options).some((option) => option.value === normalized);

    if (exists) {
        return;
    }

    const option = document.createElement('option');
    option.value = normalized;
    option.textContent = normalized === '0' ? 'Alle' : normalized;
    control.append(option);
    Array.from(control.options)
        .sort((left, right) => Number(left.value) - Number(right.value))
        .forEach((sortedOption) => control.append(sortedOption));
}

function ensureMinDegreeOption(root, value) {
    const control = root.querySelector('[data-network-filter-min-degree]');

    if (!control) {
        return;
    }

    const normalized = String(Math.max(0, Number(value) || 0));
    const exists = Array.from(control.options).some((option) => option.value === normalized);

    if (exists) {
        return;
    }

    const option = document.createElement('option');
    option.value = normalized;
    option.textContent = normalized;
    control.append(option);
    Array.from(control.options)
        .sort((left, right) => Number(left.value) - Number(right.value))
        .forEach((sortedOption) => control.append(sortedOption));
}

function recommendedMinDegree(root, cy, state) {
    const nonPersonNodes = cy.nodes().filter((node) => node.data('type') !== 'person');
    const limit = Math.max(0, Number(state.maxVisibleProfiles ?? networkMaxVisibleProfiles(root)) || 0);

    if (limit === 0 || nonPersonNodes.length <= limit) {
        return 0;
    }

    const degrees = nonPersonNodes.map((node) => visibleDegreeForState(node, state)).sort((left, right) => right - left);

    if (!degrees.length) {
        return 0;
    }

    let threshold = Math.max(0, Number(degrees[Math.min(limit - 1, degrees.length - 1)]) || 0);

    while (degrees.filter((degree) => degree >= threshold).length > limit) {
        threshold += 1;
    }

    return threshold;
}

function applyAutoMinDegreeIfNeeded(root, cy) {
    const state = instances.get(root);

    if (!state || state.hasStoredMinDegree) {
        return;
    }

    const nextMinDegree = recommendedMinDegree(root, cy, state);

    if (state.minDegree === nextMinDegree && state.autoMinDegreeApplied) {
        return;
    }

    state.minDegree = nextMinDegree;
    state.autoMinDegreeApplied = true;
    ensureMinDegreeOption(root, nextMinDegree);
}

function nodeActionDetail(root, node, extra = {}) {
    return {
        mapId: root.dataset.networkMapId || null,
        id: node.id(),
        type: node.data('type') || 'unknown',
        isKnownProfile: Boolean(node.data('isKnownProfile')),
        detailUrl: node.data('detailUrl') || null,
        ...extra,
    };
}

function dispatchOpenNode(root, node) {
    window.dispatchEvent(new CustomEvent('network-map-open-node', {
        detail: nodeActionDetail(root, node),
    }));
}

function dispatchNodeMenu(root, node, x, y) {
    window.dispatchEvent(new CustomEvent('network-map-node-menu', {
        detail: nodeActionDetail(root, node, { x, y }),
    }));
}

function bindOpenGestures(element, openCallback) {
    let pressTimer = null;

    element.addEventListener('dblclick', (event) => {
        event.preventDefault();
        openCallback(event);
    });

    element.addEventListener('touchstart', (event) => {
        pressTimer = window.setTimeout(() => {
            event.preventDefault();
            openCallback(event);
        }, 560);
    }, { passive: false });

    ['touchend', 'touchcancel', 'pointercancel', 'mouseup', 'mouseleave'].forEach((eventName) => {
        element.addEventListener(eventName, () => {
            if (pressTimer) {
                window.clearTimeout(pressTimer);
                pressTimer = null;
            }
        });
    });
}

function arrangeVisibleGraph(root, cy, animate = true) {
    const visibleNodes = cy.nodes().not('.network-filtered');

    if (!visibleNodes.length) {
        return;
    }

    const width = Math.max(1200, visibleNodes.length * 22);
    const height = Math.max(820, visibleNodes.length * 16);
    const centerX = width / 2;
    const centerY = height / 2;
    const nodes = visibleNodes.toArray();
    const primary = nodes.find((node) => Boolean(node.data('isPrimary'))) || nodes.find((node) => node.data('type') === 'person') || nodes[0];
    const anchors = [];
    const children = [];
    const anchorGroups = new Map();
    const ungrouped = [];
    const visibleEdges = cy.edges().not('.network-filtered');

    nodes.forEach((node) => {
        if (node.id() === primary.id()) {
            return;
        }

        if (node.data('type') === 'person' || node.data('type') === 'profile') {
            anchors.push(node);
            return;
        }

        children.push(node);
    });

    anchors.sort((a, b) => (
        visibleDegree(b) - visibleDegree(a)
        || String(a.data('username') || a.id()).localeCompare(String(b.data('username') || b.id()))
    ));

    const updates = [{ node: primary, position: { x: centerX, y: centerY } }];
    const anchorPositions = new Map();
    const anchorRadius = Math.max(220, Math.min(430, 180 + (anchors.length * 8)));

    anchors.forEach((node, index) => {
        const angle = (-Math.PI / 2) + ((Math.PI * 2) * (index / Math.max(1, anchors.length)));
        const ring = Math.floor(index / 28);
        const position = {
            x: centerX + Math.cos(angle) * (anchorRadius + (ring * 170)),
            y: centerY + Math.sin(angle) * (anchorRadius + (ring * 170)),
        };

        anchorPositions.set(node.id(), position);
        updates.push({ node, position });
    });

    const anchorIds = new Set([primary.id(), ...anchors.map((node) => node.id())]);
    const preferredAnchorForChild = (node) => {
        const connectedAnchors = visibleEdges
            .filter((edge) => edge.source().id() === node.id() || edge.target().id() === node.id())
            .connectedNodes()
            .filter((connectedNode) => anchorIds.has(connectedNode.id()) && connectedNode.id() !== node.id())
            .toArray()
            .sort((a, b) => (
                (a.id() === primary.id() ? -1 : 0)
                - (b.id() === primary.id() ? -1 : 0)
                || visibleDegree(b) - visibleDegree(a)
            ));

        return connectedAnchors[0] || null;
    };

    children
        .sort((a, b) => visibleDegree(b) - visibleDegree(a) || String(a.data('username') || a.id()).localeCompare(String(b.data('username') || b.id())))
        .forEach((node) => {
            const anchor = preferredAnchorForChild(node);

            if (!anchor) {
                ungrouped.push(node);
                return;
            }

            const group = anchorGroups.get(anchor.id()) || [];
            group.push(node);
            anchorGroups.set(anchor.id(), group);
        });

    anchorGroups.forEach((group, anchorId) => {
        const anchorPosition = anchorPositions.get(anchorId) || { x: centerX, y: centerY };
        const columns = Math.max(2, Math.ceil(Math.sqrt(group.length)));
        const spacing = 72;
        const rowGap = 74;

        group.forEach((node, index) => {
            const row = Math.floor(index / columns);
            const column = index % columns;
            const centeredColumn = column - ((Math.min(columns, group.length - (row * columns)) - 1) / 2);

            updates.push({
                node,
                position: {
                    x: anchorPosition.x + (centeredColumn * spacing),
                    y: anchorPosition.y + 120 + (row * rowGap),
                },
            });
        });
    });

    ungrouped.forEach((node, index) => {
        const ring = Math.floor(index / 42);
        const ringIndex = index - (ring * 42);
        const ringCount = Math.min(42, ungrouped.length - (ring * 42));
        const angle = (Math.PI * 2 * (ringIndex / Math.max(1, ringCount))) + Math.PI / 5;
        const radius = 620 + (ring * 150);

        updates.push({
            node,
            position: {
                x: centerX + Math.cos(angle) * radius,
                y: centerY + Math.sin(angle) * radius,
            },
        });
    });

    if (!anchors.length && !children.length) {
        updates[0].position = { x: centerX, y: centerY };
    }

    updates.forEach(({ node, position }) => {
        if (animate) {
            node.animate({ position }, { duration: 320 });
        } else {
            node.position(position);
        }
    });

    window.setTimeout(() => fitGraph(cy, { tight: true }), animate ? 360 : 30);
}

function setSelected(root, cy, nodeId) {
    const state = instances.get(root);

    if (!state) {
        return;
    }

    state.selectedId = nodeId;
    cy.elements().removeClass('network-selected network-neighbor network-faded');

    if (state.showDirectOnly) {
        applyFilters(root, cy);
    }

    if (nodeId) {
        const node = cy.getElementById(nodeId);
        const edges = visibleConnectedEdges(node);
        const neighborhood = edges.connectedNodes().union(edges).union(node);

        cy.elements().difference(neighborhood).addClass('network-faded');
        node.addClass('network-selected');
        edges.addClass('network-neighbor');
    }

    updateSelectionPanel(root, cy);
}

function updateSelectionPanel(root, cy) {
    const state = instances.get(root);
    const empty = root.querySelector('[data-network-detail-empty]');
    const detail = root.querySelector('[data-network-detail]');
    const list = root.querySelector('[data-network-connected-list]');

    if (!empty || !detail || !list || !state?.selectedId) {
        empty?.classList.remove('hidden');
        detail?.classList.add('hidden');

        if (list) {
            list.replaceChildren();
        }

        window.dispatchEvent(new CustomEvent('network-map-node-selected', {
            detail: {
                mapId: root.dataset.networkMapId || null,
                id: null,
                type: null,
                isKnownProfile: false,
            },
        }));

        return;
    }

    const node = cy.getElementById(state.selectedId);

    if (!node.length) {
        setSelected(root, cy, null);
        return;
    }

    empty.classList.add('hidden');
    detail.classList.remove('hidden');

    const detailAvatar = root.querySelector('[data-network-detail-avatar]');
    detailAvatar?.replaceChildren(avatarElement(node.data(), true));
    root.querySelector('[data-network-detail-label]').textContent = node.data('fullLabel') || node.data('label');
    root.querySelector('[data-network-detail-handle]').textContent = [node.data('role'), node.data('handle') || node.data('type')]
        .filter(Boolean)
        .join(' · ');
    root.querySelector('[data-network-detail-text]').textContent = node.data('detail') || 'Keine Zusatzdetails gespeichert.';
    root.querySelector('[data-network-detail-visibility]')?.replaceChildren(visibilityBadgeElement(node.data()));

    const edges = visibleConnectedEdges(node);
    root.querySelector('[data-network-detail-edge-count]').textContent = edges.length;

    window.dispatchEvent(new CustomEvent('network-map-node-selected', {
        detail: nodeActionDetail(root, node),
    }));

    const connectedNodes = edges.connectedNodes().not(node);
    list.replaceChildren();

    if (!connectedNodes.length) {
        const item = document.createElement('p');
        item.className = 'text-sm text-slate-500';
        item.textContent = 'Keine sichtbaren Verknuepfungen.';
        list.append(item);
        return;
    }

    connectedNodes.forEach((connectedNode) => {
        const button = document.createElement('button');
        const text = document.createElement('span');
        const label = document.createElement('span');
        const handle = document.createElement('span');
        const badges = document.createElement('span');
        const connection = document.createElement('span');
        const connectedEdges = edges
            .filter((edge) => edge.source().id() === connectedNode.id() || edge.target().id() === connectedNode.id());
        const edgeLabels = connectedEdges
            .map((edge) => edge.data('label'))
            .filter(Boolean);
        const uniqueEdgeLabels = [...new Set(edgeLabels)];
        const hasOtherUserEvidence = connectedEdges
            .some((edge) => Boolean(edge.data('otherUserEvidence')));
        const hasSystemWideEvidence = connectedEdges
            .some((edge) => Boolean(edge.data('systemWideEvidence')));

        button.type = 'button';
        button.className = 'flex w-full items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm transition hover:bg-white';
        button.addEventListener('click', () => setSelected(root, cy, connectedNode.id()));
        bindOpenGestures(button, () => dispatchOpenNode(root, connectedNode));

        text.className = 'min-w-0 flex-1';

        label.className = 'block font-semibold text-slate-950';
        label.textContent = connectedNode.data('fullLabel') || connectedNode.data('label');

        handle.className = 'block text-xs text-slate-500';
        handle.textContent = connectedNode.data('handle') || connectedNode.data('type');

        badges.className = 'mt-1 flex flex-wrap gap-1';
        badges.append(visibilityBadgeElement(connectedNode.data()));

        connection.className = 'mt-1 block text-xs font-semibold text-slate-600';
        connection.textContent = uniqueEdgeLabels.join(' + ') || 'Verbindung';

        if (hasOtherUserEvidence) {
            connection.textContent += ' · durch weitere Benutzer-Scans erkannt';
        } else if (hasSystemWideEvidence) {
            connection.textContent += ' · systemweit bestätigt';
        }

        text.append(label, handle, badges, connection);
        button.append(avatarElement(connectedNode.data()), text);
        list.append(button);
    });
}

function applyFilters(root, cy) {
    const state = instances.get(root);

    if (!state) {
        return;
    }

    cy.elements().removeClass('network-filtered');

    if (!state.showPublic) {
        cy.edges('[networkType = "public-profile"]').addClass('network-filtered');
    }

    if (!state.showInferred) {
        cy.edges('[networkType = "inferred"]').addClass('network-filtered');
    }

    if (!state.showTracked) {
        cy.edges('[networkType = "tracked-list"]').addClass('network-filtered');
        cy.edges('[networkType = "tracked-profile-rel"]').addClass('network-filtered');
    }

    const autoMinDegree = state.maxVisibleProfiles > 0 ? recommendedMinDegree(root, cy, state) : 0;
    const effectiveMinDegree = Math.max(state.minDegree, autoMinDegree);

    cy.nodes().forEach((node) => {
        const isPerson = node.data('type') === 'person';
        const degree = visibleDegree(node);

        if (!isPerson && degree < effectiveMinDegree) {
            node.addClass('network-filtered');
        }
    });

    if (state.showDirectOnly) {
        const directIds = directVisibleNodeIds(cy, state);

        cy.nodes().forEach((node) => {
            if (directIds.has(node.id())) {
                node.removeClass('network-filtered');
            } else {
                node.addClass('network-filtered');
            }
        });
    }

    root.querySelectorAll('[data-network-filter]').forEach((button) => {
        const filter = button.dataset.networkFilter;
        const active = {
            public: state.showPublic,
            inferred: state.showInferred,
            tracked: state.showTracked,
            direct: state.showDirectOnly,
        }[filter] ?? true;

        updateButton(button, active);
    });

    const minDegreeControl = root.querySelector('[data-network-filter-min-degree]');

    if (minDegreeControl) {
        ensureMinDegreeOption(root, state.minDegree);
        minDegreeControl.value = String(state.minDegree);
    }

    const maxProfilesControl = root.querySelector('[data-network-filter-max-profiles]');

    if (maxProfilesControl) {
        ensureMaxProfilesOption(root, state.maxVisibleProfiles);
        maxProfilesControl.value = String(state.maxVisibleProfiles);
    }

    const maxProfilesLabel = root.querySelector('[data-network-visible-profiles-count]');

    if (maxProfilesLabel) {
        const visibleProfiles = cy.nodes().filter((node) => node.data('type') !== 'person').not('.network-filtered').length;
        maxProfilesLabel.textContent = `${visibleProfiles.toLocaleString('de-DE')} sichtbar`;
    }

    const debugMinDegree = root.querySelector('[data-network-effective-min-degree]');

    if (debugMinDegree) {
        debugMinDegree.textContent = String(effectiveMinDegree);
    }

    writeStoredFilters(root, state);
    arrangeVisibleGraph(root, cy, true);
    updateSelectionPanel(root, cy);
    schedulePublicBadgeUpdate(root, cy);
}

function fitGraph(cy, options = {}) {
    cy.resize();
    const visibleNodes = cy.nodes().not('.network-filtered');

    if (!visibleNodes.length) {
        return;
    }

    const viewportMin = Math.min(cy.width(), cy.height());
    const paddingRatio = options.tight ? 0.018 : 0.035;
    const padding = Math.max(6, Math.min(options.tight ? 22 : 34, Math.floor(viewportMin * paddingRatio)));
    const bounds = visibleNodes.boundingBox();
    const viewportWidth = Math.max(1, cy.width() - (padding * 2));
    const viewportHeight = Math.max(1, cy.height() - (padding * 2));
    const requiredZoom = Math.min(
        viewportWidth / Math.max(1, bounds.w),
        viewportHeight / Math.max(1, bounds.h),
    );

    if (Number.isFinite(requiredZoom) && requiredZoom > 0 && requiredZoom < cy.minZoom()) {
        cy.minZoom(Math.max(0.002, requiredZoom * 0.92));
    }

    cy.fit(visibleNodes, padding);
    schedulePublicBadgeUpdateFromCy(cy);
}

function bindControls(root, cy) {
    const state = instances.get(root);

    root.querySelectorAll('[data-network-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.networkFilter === 'public') {
                state.showPublic = !state.showPublic;
            } else if (button.dataset.networkFilter === 'inferred') {
                state.showInferred = !state.showInferred;
            } else if (button.dataset.networkFilter === 'tracked') {
                state.showTracked = !state.showTracked;
            } else if (button.dataset.networkFilter === 'direct') {
                state.showDirectOnly = !state.showDirectOnly;
            }

            applyFilters(root, cy);
        });
    });

    root.querySelector('[data-network-filter-min-degree]')?.addEventListener('change', (event) => {
        state.minDegree = Math.max(0, Number(event.target.value) || 0);
        state.hasStoredMinDegree = true;
        applyFilters(root, cy);
    });

    root.querySelector('[data-network-filter-max-profiles]')?.addEventListener('change', (event) => {
        state.maxVisibleProfiles = Math.max(0, Number(event.target.value) || 0);
        applyFilters(root, cy);
    });

    root.querySelectorAll('[data-network-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.networkAction;

            if (action === 'zoom-in') {
                cy.zoom({ level: Math.min(4.5, cy.zoom() + 0.18), renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
            }

            if (action === 'zoom-out') {
                cy.zoom({
                    level: Math.max(cy.minZoom(), cy.zoom() - Math.max(0.03, cy.zoom() * 0.22)),
                    renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 },
                });
            }

            if (action === 'fit') {
                setSelected(root, cy, null);
                fitGraph(cy, { tight: true });
            }
        });
    });
}

async function initNetworkMap(root) {
    if (instances.has(root)) {
        return instances.get(root);
    }

    if (root.networkMapInitPromise) {
        return root.networkMapInitPromise;
    }

    root.networkMapInitPromise = (async () => {
        const container = root.querySelector('[data-network-canvas]');
        const graph = readGraph(root);

        if (!container) {
            return null;
        }

        root.dataset.networkMapInitializing = 'true';
        const cytoscape = await loadCytoscape();

        if (!document.body.contains(root)) {
            delete root.dataset.networkMapInitializing;
            return null;
        }

        const initialElements = toElements(graph);
        const initialLayout = initialElements.length
            ? (root.dataset.networkLazy === 'true' ? { name: 'preset' } : coseLayoutOptions(true))
            : { name: 'preset' };

        const cy = cytoscape({
        container,
        elements: initialElements,
        minZoom: 0.02,
        maxZoom: 4.5,
        wheelSensitivity: 0.16,
        style: [
            {
                selector: 'node',
                style: {
                    width: 'data(nodeSize)',
                    height: 'data(nodeSize)',
                    'background-color': '#eff6ff',
                    'border-color': '#94a3b8',
                    'border-width': 2,
                    color: '#0f172a',
                    label: 'data(label)',
                    'font-size': 'data(nodeFontSize)',
                    'font-weight': 700,
                    'text-background-color': '#ffffff',
                    'text-background-opacity': 0.88,
                    'text-background-padding': 4,
                    'text-border-color': '#e2e8f0',
                    'text-border-width': 1,
                    'text-border-opacity': 0.9,
                    'text-margin-y': 10,
                    'text-max-width': 105,
                    'text-valign': 'bottom',
                    'text-wrap': 'wrap',
                },
            },
            {
                selector: 'node[type = "person"]',
                style: {
                    'background-color': '#0f172a',
                    'border-color': '#38bdf8',
                    'border-width': 3,
                    color: '#ffffff',
                    'text-background-color': '#0f172a',
                    'text-border-color': '#0f172a',
                },
            },
            {
                selector: 'node[type = "candidate"]',
                style: {
                    'background-color': '#f8fafc',
                    'border-color': '#cbd5e1',
                },
            },
            {
                selector: '.network-profile-public',
                style: {
                    'border-color': '#34d399',
                },
            },
            {
                selector: '.network-profile-private',
                style: {
                    'border-color': '#64748b',
                },
            },
            {
                selector: '.network-has-image',
                style: {
                    'background-image': 'data(imageUrl)',
                    'background-fit': 'cover',
                    'background-clip': 'node',
                    'background-color': '#f8fafc',
                    'background-opacity': 1,
                },
            },
            {
                selector: '.network-primary',
                style: {
                    'border-color': '#f59e0b',
                    'border-width': 6,
                    'z-index': 30,
                    'text-background-color': '#fffbeb',
                    'text-border-color': '#fbbf24',
                    color: '#78350f',
                },
            },
            {
                selector: 'edge',
                style: {
                    width: 0.7,
                    'line-color': '#7dd3fc',
                    'target-arrow-color': '#7dd3fc',
                    'target-arrow-shape': 'triangle',
                    'arrow-scale': 0.65,
                    'curve-style': 'bezier',
                    opacity: 0.38,
                },
            },
            {
                selector: 'edge[networkType = "inferred"]',
                style: {
                    'line-color': '#f9a8d4',
                    'target-arrow-color': '#f9a8d4',
                    'line-style': 'dashed',
                },
            },
            {
                selector: 'edge[networkType = "tracked-list"]',
                style: {
                    width: 0.9,
                    'line-color': '#6ee7b7',
                    'target-arrow-color': '#6ee7b7',
                    'line-style': 'solid',
                    opacity: 0.46,
                },
            },
            {
                selector: 'edge[networkType = "tracked-profile-rel"]',
                style: {
                    width: 0.9,
                    'line-color': '#a5b4fc',
                    'target-arrow-color': '#a5b4fc',
                    'line-style': 'solid',
                    opacity: 0.46,
                },
            },
            {
                selector: 'edge[otherUserEvidence]',
                style: {
                    width: 1.1,
                    'line-color': '#a78bfa',
                    'target-arrow-color': '#a78bfa',
                    opacity: 0.58,
                },
            },
            {
                selector: '.network-selected',
                style: {
                    'border-color': '#f59e0b',
                    'border-width': 5,
                    'z-index': 20,
                },
            },
            {
                selector: '.network-neighbor',
                style: {
                    opacity: 0.82,
                    width: 1.5,
                    'z-index': 10,
                },
            },
            {
                selector: '.network-faded',
                style: {
                    opacity: 0.14,
                },
            },
            {
                selector: '.network-filtered',
                style: {
                    display: 'none',
                },
            },
        ],
            layout: initialLayout,
        });

        const resizeHandler = () => fitGraph(cy, { tight: true });

        const storedFilters = readStoredFilters(root);
        const storedMinDegree = Number.isFinite(Number(storedFilters.minDegree))
            ? Math.max(0, Number(storedFilters.minDegree))
            : null;
        const storedMaxVisibleProfiles = Number.isFinite(Number(storedFilters.maxVisibleProfiles))
            ? Math.max(0, Number(storedFilters.maxVisibleProfiles))
            : null;
        const state = {
            cy,
            showPublic: storedFilters.showPublic ?? true,
            showInferred: storedFilters.showInferred ?? true,
            showTracked: storedFilters.showTracked ?? true,
            showDirectOnly: storedFilters.showDirectOnly ?? false,
            minDegree: storedMinDegree ?? 0,
            maxVisibleProfiles: storedMaxVisibleProfiles ?? selectedMaxVisibleProfiles(root),
            hasStoredMinDegree: storedMinDegree !== null,
            autoMinDegreeApplied: false,
            selectedId: null,
            resizeHandler,
            loadGeneration: 0,
            loadedNodes: initialElements.filter((element) => element.group === 'nodes').length,
            loadedEdges: initialElements.filter((element) => element.group === 'edges').length,
            lastNodeTap: null,
            nodeTapTimer: null,
        };

        instances.set(root, state);
        delete root.dataset.networkMapInitializing;
        activeRoots.add(root);

        bindControls(root, cy);
        bindPublicBadges(root, cy);
        applyAutoMinDegreeIfNeeded(root, cy);
        applyFilters(root, cy);

        cy.on('tap', 'node', (event) => {
            const node = event.target;
            const now = Date.now();
            const lastTap = state.lastNodeTap;
            const isDoubleTap = lastTap
                && lastTap.id === node.id()
                && now - lastTap.at <= 360;

            setSelected(root, cy, node.id());

            if (isDoubleTap) {
                window.clearTimeout(state.nodeTapTimer);
                state.nodeTapTimer = null;
                state.lastNodeTap = null;
                dispatchOpenNode(root, node);

                return;
            }

            state.lastNodeTap = { id: node.id(), at: now };
            const containerRect = cy.container().getBoundingClientRect();
            const rendered = event.renderedPosition || { x: cy.width() / 2, y: cy.height() / 2 };
            const menuX = Math.min(window.innerWidth - 240, Math.max(8, containerRect.left + rendered.x + 12));
            const menuY = Math.min(window.innerHeight - 190, Math.max(8, containerRect.top + rendered.y + 12));

            window.clearTimeout(state.nodeTapTimer);
            state.nodeTapTimer = window.setTimeout(() => {
                dispatchNodeMenu(root, node, menuX, menuY);
                state.lastNodeTap = null;
                state.nodeTapTimer = null;
            }, 360);
        });
        cy.on('taphold', 'node', (event) => {
            const node = event.target;
            const containerRect = cy.container().getBoundingClientRect();
            const rendered = event.renderedPosition || { x: cy.width() / 2, y: cy.height() / 2 };

            setSelected(root, cy, node.id());
            dispatchNodeMenu(
                root,
                node,
                Math.min(window.innerWidth - 240, Math.max(8, containerRect.left + rendered.x + 12)),
                Math.min(window.innerHeight - 150, Math.max(8, containerRect.top + rendered.y + 12)),
            );
        });
        cy.on('tap', (event) => {
            if (event.target === cy) {
                setSelected(root, cy, null);
            }
        });

        if (initialElements.length) {
            updateBuildStatus(root, { visible: false, state: 'done' });
            window.setTimeout(() => arrangeVisibleGraph(root, cy, false), 150);
        } else {
            updateBuildStatus(root, {
                visible: true,
                label: 'Netzwerk wird vorbereitet',
                text: 'Die gespeicherten Profile und Listen werden nachgeladen.',
                count: 'Warte auf Daten',
                progress: 0,
            });
        }

        window.addEventListener('resize', resizeHandler);

        return state;
    })();

    try {
        return await root.networkMapInitPromise;
    } finally {
        delete root.networkMapInitPromise;
    }
}

function coseLayoutOptions(animate = false) {
    return {
        name: 'cose',
        animate,
        animationDuration: animate ? 650 : 0,
        componentSpacing: 120,
        idealEdgeLength: 120,
        nodeOverlap: 18,
        padding: 70,
        refresh: 20,
    };
}

function largeGraphLayoutOptions() {
    return {
        name: 'concentric',
        animate: false,
        avoidOverlap: true,
        minNodeSpacing: 10,
        padding: 70,
        concentric: (node) => {
            if (node.data('isPrimary')) {
                return 10;
            }

            if (node.data('type') === 'person') {
                return 8;
            }

            return Math.min(6, node.degree(false));
        },
        levelWidth: () => 1,
    };
}

function finalLayoutOptions(cy) {
    return cy.nodes().length > 1200 ? largeGraphLayoutOptions() : coseLayoutOptions(true);
}

function addGraphChunk(root, cy, chunk) {
    const nodeElements = toElements({ nodes: chunk.nodes || [], edges: [] })
        .filter((element) => !cy.getElementById(element.data.id).length);

    if (nodeElements.length) {
        cy.add(nodeElements);
    }

    const edgeElements = toElements({ nodes: [], edges: chunk.edges || [] })
        .filter((element) => {
            const sourceExists = cy.getElementById(element.data.source).length > 0;
            const targetExists = cy.getElementById(element.data.target).length > 0;

            return sourceExists && targetExists && !cy.getElementById(element.data.id).length;
        });

    if (edgeElements.length) {
        cy.add(edgeElements);
    }

    const state = instances.get(root);

    if (state) {
        state.loadedNodes += nodeElements.length;
        state.loadedEdges += edgeElements.length;
    }
}

function resetGraph(root) {
    const state = instances.get(root);

    if (!state) {
        updateBuildStatus(root, {
            visible: true,
            label: 'Netzwerk wird vorbereitet',
            text: 'Die gespeicherten Profile und Listen werden nachgeladen.',
            count: 'Warte auf Daten',
            progress: 0,
        });

        return;
    }

    state.loadGeneration += 1;
    state.loadedNodes = 0;
    state.loadedEdges = 0;
    state.selectedId = null;
    state.cy.elements().remove();
    updateSelectionPanel(root, state.cy);
    schedulePublicBadgeUpdate(root, state.cy);
    updateBuildStatus(root, {
        visible: true,
        label: 'Netzwerk wird vorbereitet',
        text: 'Die gespeicherten Profile und Listen werden nachgeladen.',
        count: 'Warte auf Daten',
        progress: 0,
    });
}

function chunkUrl(template, index) {
    return String(template || '').replace('__CHUNK__', String(index));
}

async function loadPreparedGraph(root, detail) {
    const state = await initNetworkMap(root);

    if (!state || !detail?.chunkUrl || !Number.isFinite(Number(detail.chunkCount))) {
        return;
    }

    const cy = state.cy;
    const chunkCount = Number(detail.chunkCount);
    const generation = state.loadGeneration + 1;
    state.loadGeneration = generation;
    state.loadedNodes = 0;
    state.loadedEdges = 0;
    state.selectedId = null;
    cy.elements().remove();
    updateSelectionPanel(root, cy);
    schedulePublicBadgeUpdate(root, cy);

    updateBuildStatus(root, {
        visible: true,
        label: 'Netzwerk wird geladen',
        text: 'Knoten und Kanten werden stueckweise in die Grafik eingefuegt.',
        count: `0 von ${chunkCount} Paketen`,
        progress: 0,
    });

    for (let index = 0; index < chunkCount; index += 1) {
        if (state.loadGeneration !== generation) {
            return;
        }

        const response = await fetch(chunkUrl(detail.chunkUrl, index), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Netzwerkpaket ${index + 1} konnte nicht geladen werden.`);
        }

        const chunk = await response.json();
        addGraphChunk(root, cy, chunk);
        applyFilters(root, cy);
        schedulePublicBadgeUpdate(root, cy);

        const progress = ((index + 1) / chunkCount) * 100;
        updateBuildStatus(root, {
            visible: true,
            label: chunk.stage === 'edges' ? 'Verbindungen werden eingefuegt' : 'Profile werden eingefuegt',
            text: `${state.loadedNodes.toLocaleString('de-DE')} Knoten und ${state.loadedEdges.toLocaleString('de-DE')} Kanten geladen.`,
            count: `${index + 1} von ${chunkCount} Paketen`,
            progress,
        });

        if (index === 0 || index % 4 === 0) {
            fitGraph(cy, { tight: true });
        }

        await new Promise((resolve) => window.requestAnimationFrame(resolve));
    }

    if (state.loadGeneration !== generation) {
        return;
    }

    applyAutoMinDegreeIfNeeded(root, cy);
    applyFilters(root, cy);
    updateBuildStatus(root, {
        visible: true,
        label: 'Netzwerk geladen',
        text: `${state.loadedNodes.toLocaleString('de-DE')} Knoten und ${state.loadedEdges.toLocaleString('de-DE')} Kanten sichtbar.`,
        count: 'Fertig',
        progress: 100,
        state: 'done',
    });

    window.requestAnimationFrame(() => {
        arrangeVisibleGraph(root, cy, false);
        window.setTimeout(() => fitGraph(cy, { tight: true }), 60);
        window.setTimeout(() => updateBuildStatus(root, { visible: false, state: 'done' }), 900);
    });
}

async function handlePreparedGraph(event) {
    const detail = eventDetail(event);
    const root = detail.mapId
        ? document.querySelector(`[data-network-map-root][data-network-map-id="${detail.mapId}"]`)
        : document.querySelector('[data-network-map-root]');

    if (!root) {
        return;
    }

    try {
        await loadPreparedGraph(root, detail);
    } catch (error) {
        console.error(error);
        updateBuildStatus(root, {
            visible: true,
            label: 'Netzwerk konnte nicht geladen werden',
            text: error.message || 'Beim Laden der Netzwerkpakete ist ein Fehler aufgetreten.',
            count: 'Fehler',
            progress: 100,
            state: 'error',
        });
    }
}

export function initNetworkMaps(scope = document) {
    scope.querySelectorAll('[data-network-map-root]').forEach(initNetworkMap);
}

export function destroyNetworkMaps() {
    activeRoots.forEach((root) => {
        const state = instances.get(root);

        if (!state) {
            return;
        }

        window.removeEventListener('resize', state.resizeHandler);
        window.clearTimeout(state.nodeTapTimer);
        state.cy.destroy();
        instances.delete(root);
    });

    activeRoots.clear();
}

window.addEventListener('network-map-layout-refresh', (event) => {
    const detail = eventDetail(event);
    const root = detail.mapId
        ? document.querySelector(`[data-network-map-root][data-network-map-id="${detail.mapId}"]`)
        : document.querySelector('[data-network-map-root]');

    if (!root) {
        return;
    }

    const state = instances.get(root);

    if (!state?.cy) {
        return;
    }

    window.requestAnimationFrame(() => {
        state.cy.resize();
        arrangeVisibleGraph(root, state.cy, false);
        window.setTimeout(() => fitGraph(state.cy, { tight: true }), 60);
    });
});

document.addEventListener('DOMContentLoaded', () => initNetworkMaps());
document.addEventListener('livewire:navigating', () => destroyNetworkMaps());
document.addEventListener('livewire:navigated', () => initNetworkMaps());
window.addEventListener('network-map-graph-prepared', handlePreparedGraph);
window.addEventListener('network-map-empty', (event) => {
    const detail = eventDetail(event);
    const root = detail.mapId
        ? document.querySelector(`[data-network-map-root][data-network-map-id="${detail.mapId}"]`)
        : document.querySelector('[data-network-map-root]');

    if (root) {
        resetGraph(root);
        updateBuildStatus(root, {
            visible: true,
            label: 'Keine Netzwerkdaten',
            text: 'Lege zuerst Personen oder Instagram-Listen an.',
            count: 'Keine Daten',
            progress: 0,
        });
    }
});
window.addEventListener('network-map-reset', (event) => {
    const detail = eventDetail(event);
    const root = detail.mapId
        ? document.querySelector(`[data-network-map-root][data-network-map-id="${detail.mapId}"]`)
        : document.querySelector('[data-network-map-root]');

    if (root) {
        resetGraph(root);
    }
});
window.addEventListener('network-map-refresh', () => {
    destroyNetworkMaps();
    window.requestAnimationFrame(() => initNetworkMaps());
});
