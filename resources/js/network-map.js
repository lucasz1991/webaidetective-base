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

function toElements(graph) {
    const nodes = (graph.nodes || []).map((node) => ({
        group: 'nodes',
        classes: [
            node.hasImage ? 'network-has-image' : '',
            node.isPrimary ? 'network-primary' : '',
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

function visibleConnectedEdges(node) {
    return node.connectedEdges().not('.network-filtered');
}

function setSelected(root, cy, nodeId) {
    const state = instances.get(root);

    if (!state) {
        return;
    }

    state.selectedId = nodeId;
    cy.elements().removeClass('network-selected network-neighbor network-faded');

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

        return;
    }

    const node = cy.getElementById(state.selectedId);

    if (!node.length) {
        setSelected(root, cy, null);
        return;
    }

    empty.classList.add('hidden');
    detail.classList.remove('hidden');

    root.querySelector('[data-network-detail-label]').textContent = node.data('fullLabel') || node.data('label');
    root.querySelector('[data-network-detail-handle]').textContent = [node.data('role'), node.data('handle') || node.data('type')]
        .filter(Boolean)
        .join(' · ');
    root.querySelector('[data-network-detail-text]').textContent = node.data('detail') || 'Keine Zusatzdetails gespeichert.';

    const edges = visibleConnectedEdges(node);
    root.querySelector('[data-network-detail-edge-count]').textContent = edges.length;

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
        const label = document.createElement('span');
        const handle = document.createElement('span');
        const connection = document.createElement('span');
        const edgeLabels = edges
            .filter((edge) => edge.source().id() === connectedNode.id() || edge.target().id() === connectedNode.id())
            .map((edge) => edge.data('label'))
            .filter(Boolean);
        const uniqueEdgeLabels = [...new Set(edgeLabels)];

        button.type = 'button';
        button.className = 'block w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm transition hover:bg-white';
        button.addEventListener('click', () => setSelected(root, cy, connectedNode.id()));

        label.className = 'block font-semibold text-slate-950';
        label.textContent = connectedNode.data('fullLabel') || connectedNode.data('label');

        handle.className = 'block text-xs text-slate-500';
        handle.textContent = connectedNode.data('handle') || connectedNode.data('type');

        connection.className = 'mt-1 block text-xs font-semibold text-slate-600';
        connection.textContent = uniqueEdgeLabels.join(' + ') || 'Verbindung';

        button.append(label, handle, connection);
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
    }

    cy.nodes().forEach((node) => {
        const isPerson = node.data('type') === 'person';
        const hasVisibleEdge = visibleConnectedEdges(node).length > 0;

        if (!isPerson && !hasVisibleEdge) {
            node.addClass('network-filtered');
        }
    });

    root.querySelectorAll('[data-network-filter]').forEach((button) => {
        const filter = button.dataset.networkFilter;
        const active = {
            public: state.showPublic,
            inferred: state.showInferred,
            tracked: state.showTracked,
        }[filter] ?? true;

        updateButton(button, active);
    });

    updateSelectionPanel(root, cy);
}

function fitGraph(cy) {
    cy.resize();
    const visible = cy.elements().not('.network-filtered');

    if (visible.length) {
        const padding = Math.max(16, Math.min(56, Math.floor(Math.min(cy.width(), cy.height()) * 0.08)));
        const bounds = visible.boundingBox();
        const viewportWidth = Math.max(1, cy.width() - (padding * 2));
        const viewportHeight = Math.max(1, cy.height() - (padding * 2));
        const requiredZoom = Math.min(
            viewportWidth / Math.max(1, bounds.w),
            viewportHeight / Math.max(1, bounds.h),
        );

        if (Number.isFinite(requiredZoom) && requiredZoom > 0 && requiredZoom < cy.minZoom()) {
            cy.minZoom(Math.max(0.002, requiredZoom * 0.85));
        }

        cy.fit(visible, padding);
    }
}

function bindControls(root, cy) {
    const state = instances.get(root);

    root.querySelectorAll('[data-network-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.networkFilter === 'public') {
                state.showPublic = !state.showPublic;
            } else if (button.dataset.networkFilter === 'inferred') {
                state.showInferred = !state.showInferred;
            } else {
                state.showTracked = !state.showTracked;
            }

            applyFilters(root, cy);
        });
    });

    root.querySelectorAll('[data-network-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.networkAction;

            if (action === 'zoom-in') {
                cy.zoom({ level: Math.min(2.2, cy.zoom() + 0.18), renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
            }

            if (action === 'zoom-out') {
                cy.zoom({
                    level: Math.max(cy.minZoom(), cy.zoom() - Math.max(0.03, cy.zoom() * 0.22)),
                    renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 },
                });
            }

            if (action === 'fit') {
                setSelected(root, cy, null);
                fitGraph(cy);
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
        maxZoom: 2.2,
        wheelSensitivity: 0.16,
        style: [
            {
                selector: 'node',
                style: {
                    width: 48,
                    height: 48,
                    'background-color': '#eff6ff',
                    'border-color': '#94a3b8',
                    'border-width': 2,
                    color: '#0f172a',
                    label: 'data(label)',
                    'font-size': 11,
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
                    width: 68,
                    height: 68,
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
                    'background-color': '#fdf2f8',
                    'border-color': '#f9a8d4',
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
                    width: 94,
                    height: 94,
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
                    width: 2,
                    'line-color': '#0284c7',
                    'target-arrow-color': '#0284c7',
                    'target-arrow-shape': 'triangle',
                    'curve-style': 'bezier',
                    opacity: 0.7,
                },
            },
            {
                selector: 'edge[networkType = "inferred"]',
                style: {
                    'line-color': '#db2777',
                    'target-arrow-color': '#db2777',
                    'line-style': 'dashed',
                },
            },
            {
                selector: 'edge[networkType = "tracked-list"]',
                style: {
                    width: 3,
                    'line-color': '#059669',
                    'target-arrow-color': '#059669',
                    'line-style': 'solid',
                    opacity: 0.86,
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
                    opacity: 1,
                    width: 4,
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

        const resizeHandler = () => fitGraph(cy);

        const state = {
            cy,
            showPublic: true,
            showInferred: true,
            showTracked: true,
            selectedId: null,
            resizeHandler,
            loadGeneration: 0,
            loadedNodes: initialElements.filter((element) => element.group === 'nodes').length,
            loadedEdges: initialElements.filter((element) => element.group === 'edges').length,
        };

        instances.set(root, state);
        delete root.dataset.networkMapInitializing;
        activeRoots.add(root);

        bindControls(root, cy);
        applyFilters(root, cy);

        cy.on('tap', 'node', (event) => setSelected(root, cy, event.target.id()));
        cy.on('tap', (event) => {
            if (event.target === cy) {
                setSelected(root, cy, null);
            }
        });

        if (initialElements.length) {
            window.setTimeout(() => fitGraph(cy), 150);
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

        const progress = ((index + 1) / chunkCount) * 100;
        updateBuildStatus(root, {
            visible: true,
            label: chunk.stage === 'edges' ? 'Verbindungen werden eingefuegt' : 'Profile werden eingefuegt',
            text: `${state.loadedNodes.toLocaleString('de-DE')} Knoten und ${state.loadedEdges.toLocaleString('de-DE')} Kanten geladen.`,
            count: `${index + 1} von ${chunkCount} Paketen`,
            progress,
        });

        if (index === 0 || index % 4 === 0) {
            fitGraph(cy);
        }

        await new Promise((resolve) => window.requestAnimationFrame(resolve));
    }

    if (state.loadGeneration !== generation) {
        return;
    }

    updateBuildStatus(root, {
        visible: true,
        label: 'Layout wird berechnet',
        text: `${state.loadedNodes.toLocaleString('de-DE')} Knoten und ${state.loadedEdges.toLocaleString('de-DE')} Kanten sind geladen.`,
        count: 'Grafik wird angeordnet',
        progress: 100,
    });

    const layout = cy.layout(finalLayoutOptions(cy));

    layout.one('layoutstop', () => {
        window.requestAnimationFrame(() => {
            fitGraph(cy);
            updateBuildStatus(root, {
                visible: true,
                label: 'Netzwerk geladen',
                text: `${state.loadedNodes.toLocaleString('de-DE')} Knoten und ${state.loadedEdges.toLocaleString('de-DE')} Kanten sichtbar.`,
                count: 'Fertig',
                progress: 100,
                state: 'done',
            });
            window.setTimeout(() => updateBuildStatus(root, { visible: false, state: 'done' }), 1200);
        });
    });

    layout.run();
}

async function handlePreparedGraph(event) {
    const root = document.querySelector('[data-network-map-root]');

    if (!root) {
        return;
    }

    try {
        await loadPreparedGraph(root, eventDetail(event));
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
        state.cy.destroy();
        instances.delete(root);
    });

    activeRoots.clear();
}

document.addEventListener('DOMContentLoaded', () => initNetworkMaps());
document.addEventListener('livewire:navigating', () => destroyNetworkMaps());
document.addEventListener('livewire:navigated', () => initNetworkMaps());
window.addEventListener('network-map-graph-prepared', handlePreparedGraph);
window.addEventListener('network-map-empty', () => {
    const root = document.querySelector('[data-network-map-root]');

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
window.addEventListener('network-map-reset', () => {
    const root = document.querySelector('[data-network-map-root]');

    if (root) {
        resetGraph(root);
    }
});
window.addEventListener('network-map-refresh', () => {
    destroyNetworkMaps();
    window.requestAnimationFrame(() => initNetworkMaps());
});
