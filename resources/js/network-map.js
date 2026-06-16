const instances = new WeakMap();
const activeRoots = new Set();
const DEFAULT_LAYOUT_MODE = 'clusters';
const LAYOUT_MODES = new Set(['clusters', 'spiral', 'radial', 'concentric', 'grid']);
const LAYOUT_STORAGE_PREFIX = 'network-map-render:v8';
let cytoscapeLoader;
let threeLoader;

function loadCytoscape() {
    cytoscapeLoader ??= import('cytoscape').then((module) => module.default || module);

    return cytoscapeLoader;
}

function loadThree() {
    threeLoader ??= import('three').then((module) => module.default || module);

    return threeLoader;
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
    const visibility = visibilityValue(data);
    const borderClass = visibility === 'public' ? 'border-emerald-400' : 'border-slate-400';
    const commonClasses = `${sizeClass} shrink-0 rounded-full border ${borderClass}`;

    if (imageUrl) {
        element.src = imageUrl;
        element.alt = data?.handle || data?.fullLabel || 'Instagram-Profilbild';
        element.loading = 'lazy';
        element.referrerPolicy = 'no-referrer';
        element.className = `${commonClasses} object-cover bg-slate-100`;

        if (visibility !== 'public') {
            element.style.filter = 'grayscale(50%)';
        }

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
    return root.querySelector('[data-network-profile-overlays]')
        || root.querySelector('[data-network-public-badges]');
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

    cy.nodes('.network-profile-muted-image')
        .not('.network-filtered')
        .filter((node) => node.data('type') !== 'person' && Boolean(node.data('hasImage')) && Boolean(node.data('imageUrl')))
        .forEach((node) => {
            const position = node.renderedPosition();
            const nodeSize = Number(node.renderedWidth?.() || node.data('renderNodeSize') || node.data('nodeSize') || 42);
            const imageSize = Math.max(12, Math.round(nodeSize - Math.max(6, nodeSize * 0.13)));
            const left = position.x - (imageSize / 2);
            const top = position.y - (imageSize / 2);

            if (left < -imageSize || top < -imageSize || left > width + imageSize || top > height + imageSize) {
                return;
            }

            const image = document.createElement('img');
            image.className = 'network-muted-profile-image';
            image.src = String(node.data('imageUrl') || '');
            image.alt = node.data('handle') || node.data('fullLabel') || 'Instagram-Profilbild';
            image.loading = 'lazy';
            image.referrerPolicy = 'no-referrer';
            image.style.cssText = [
                'position:absolute',
                `left:${left}px`,
                `top:${top}px`,
                `width:${imageSize}px`,
                `height:${imageSize}px`,
                'border-radius:9999px',
                'object-fit:cover',
                'filter:grayscale(50%)',
                'pointer-events:none',
                `opacity:${node.hasClass('network-faded') ? '0.18' : '1'}`,
            ].join(';');
            fragment.append(image);
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
    const nodes = (graph.nodes || []).map((node) => {
        const visibility = visibilityValue(node);
        const isProfileNode = node.type !== 'person';

        return {
            group: 'nodes',
            classes: [
                node.hasImage ? 'network-has-image' : '',
                node.isPrimary ? 'network-primary' : '',
                node.isFocus ? 'network-focus' : '',
                isProfileNode && visibility === 'public' ? 'network-profile-public' : '',
                isProfileNode && visibility === 'private' ? 'network-profile-private' : '',
                isProfileNode && visibility === 'unknown' ? 'network-profile-unknown' : '',
                isProfileNode && node.hasImage && visibility !== 'public' ? 'network-profile-muted-image' : '',
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
                renderNodeSize: Number(node.nodeSize) || baseNodeSizeForData(node),
                renderNodeFontSize: Number(node.nodeFontSize) || baseNodeFontSizeForData(node),
                renderTextMaxWidth: 105,
            },
        };
    });

    const edges = combineGraphEdges(graph.edges || []).map((edge) => ({
        group: 'edges',
        data: edge,
    }));

    return [...nodes, ...edges];
}

function edgeEndpointId(value) {
    return String(value || '').trim();
}

function canonicalEdgePair(edge) {
    const from = edgeEndpointId(edge.from || edge.source);
    const to = edgeEndpointId(edge.to || edge.target);
    const ordered = [from, to].sort();

    return {
        from,
        to,
        source: ordered[0],
        target: ordered[1],
        key: `${ordered[0]}|${ordered[1]}`,
    };
}

function edgeEvidenceKind(edge) {
    const type = String(edge.type || edge.networkType || '').trim();
    const id = String(edge.id || '').toLowerCase();
    const label = String(edge.label || '').toLowerCase();

    if (id.includes('suggestion_connection') || label.includes('vorschlag')) {
        return 'suggestion';
    }

    if (type === 'tracked-list' || type === 'tracked-profile-rel' || type === 'public-profile') {
        return 'actual';
    }

    if (type === 'inferred') {
        return 'reconstructed';
    }

    return 'reconstructed';
}

function edgeEvidenceFromEdge(edge) {
    const pair = canonicalEdgePair(edge);
    const kind = edgeEvidenceKind(edge);
    const type = String(edge.type || edge.networkType || 'unknown');
    const label = edge.label || (kind === 'suggestion' ? 'Vorschlag' : (kind === 'actual' ? 'folgt' : 'rekonstruiert'));

    return {
        id: String(edge.id || `${type}-${pair.from}-${pair.to}`),
        type,
        kind,
        from: pair.from,
        to: pair.to,
        label,
        directional: kind !== 'suggestion' && pair.from !== pair.to,
        sourceHandle: edge.sourceHandle || null,
        evidence: edge.evidence || [],
        systemWideEvidence: Boolean(edge.systemWideEvidence),
        ownUserEvidence: Boolean(edge.ownUserEvidence),
        otherUserEvidence: Boolean(edge.otherUserEvidence),
        systemEvidenceScanCount: Number(edge.systemEvidenceScanCount || 0),
        systemEvidenceUserCount: Number(edge.systemEvidenceUserCount || 0),
    };
}

function uniqueEdgeEvidences(evidences) {
    const seen = new Set();

    return (evidences || []).filter((evidence) => {
        const key = `${evidence.type}:${evidence.kind}:${evidence.from}>${evidence.to}:${evidence.label}`;

        if (seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });
}

function edgeKindPriority(kind) {
    return { actual: 3, reconstructed: 2, suggestion: 1 }[kind] || 0;
}

function actualConnectionState(evidences, mutualFollowing) {
    if (mutualFollowing) {
        return 'mutual';
    }

    const types = new Set(evidences.map((evidence) => evidence.type));

    if (types.has('tracked-profile-rel')) {
        return 'known-person-link';
    }

    if (types.has('tracked-list')) {
        return 'following';
    }

    return 'known-profile';
}

function edgeRenderState(source, target, evidences) {
    const visibleEvidences = evidences?.length ? evidences : [];
    const strongest = visibleEvidences
        .map((evidence) => evidence.kind)
        .sort((left, right) => edgeKindPriority(right) - edgeKindPriority(left))[0] || 'reconstructed';
    const directionalEvidences = visibleEvidences.filter((evidence) => evidence.directional);
    const confirmedListEvidences = directionalEvidences.filter(
        (evidence) => evidence.kind === 'actual' && evidence.type === 'tracked-list',
    );
    const hasForwardDirection = directionalEvidences.some((evidence) => evidence.from === source && evidence.to === target);
    const hasReverseDirection = directionalEvidences.some((evidence) => evidence.from === target && evidence.to === source);
    const hasConfirmedForwardDirection = confirmedListEvidences.some(
        (evidence) => evidence.from === source && evidence.to === target,
    );
    const hasConfirmedReverseDirection = confirmedListEvidences.some(
        (evidence) => evidence.from === target && evidence.to === source,
    );
    const mutualFollowing = strongest === 'actual'
        && hasConfirmedForwardDirection
        && hasConfirmedReverseDirection;
    const connectionState = strongest === 'actual'
        ? actualConnectionState(visibleEvidences.filter((evidence) => evidence.kind === 'actual'), mutualFollowing)
        : strongest;
    const lineColor = {
        mutual: '#059669',
        following: '#22c55e',
        'known-profile': '#0284c7',
        'known-person-link': '#7c3aed',
        reconstructed: '#e11d48',
        suggestion: '#f59e0b',
    }[connectionState] || '#64748b';
    const labels = [...new Set(visibleEvidences.map((evidence) => evidence.label).filter(Boolean))];

    return {
        connectionState,
        mutualFollowing,
        lineColor,
        sourceArrowColor: lineColor,
        targetArrowColor: lineColor,
        sourceArrowShape: hasReverseDirection ? 'triangle' : 'none',
        targetArrowShape: hasForwardDirection ? 'triangle' : 'none',
        lineStyle: strongest === 'suggestion' ? 'dotted' : (strongest === 'reconstructed' ? 'dashed' : 'solid'),
        edgeWidth: strongest === 'actual' ? (mutualFollowing ? 2.7 : 1.35) : 1.05,
        edgeOpacity: strongest === 'suggestion' ? 0.66 : 0.78,
        label: labels.join(' + ') || (strongest === 'suggestion' ? 'Vorschlag' : (strongest === 'actual' ? 'folgt' : 'rekonstruiert')),
    };
}

function combinedEdgeData(source, target, evidences) {
    const uniqueEvidences = uniqueEdgeEvidences(evidences);
    const renderState = edgeRenderState(source, target, uniqueEvidences);
    const networkTypes = [...new Set(uniqueEvidences.map((evidence) => evidence.type).filter(Boolean))];

    return {
        id: `edge-${stableHash(`${source}|${target}`)}`,
        source,
        target,
        from: source,
        to: target,
        type: networkTypes[0] || 'combined',
        networkType: networkTypes[0] || 'combined',
        networkTypes,
        edgeEvidences: uniqueEvidences,
        systemWideEvidence: uniqueEvidences.some((evidence) => evidence.systemWideEvidence),
        ownUserEvidence: uniqueEvidences.some((evidence) => evidence.ownUserEvidence),
        otherUserEvidence: uniqueEvidences.some((evidence) => evidence.otherUserEvidence),
        systemEvidenceScanCount: uniqueEvidences.reduce((count, evidence) => count + Number(evidence.systemEvidenceScanCount || 0), 0),
        systemEvidenceUserCount: Math.max(0, ...uniqueEvidences.map((evidence) => Number(evidence.systemEvidenceUserCount || 0))),
        ...renderState,
    };
}

function combineGraphEdges(edges) {
    const groups = new Map();

    (edges || []).forEach((edge) => {
        const pair = canonicalEdgePair(edge);

        if (!pair.source || !pair.target || pair.source === pair.target) {
            return;
        }

        const group = groups.get(pair.key) || {
            source: pair.source,
            target: pair.target,
            evidences: [],
        };

        group.evidences.push(edgeEvidenceFromEdge(edge));
        groups.set(pair.key, group);
    });

    return Array.from(groups.values()).map((group) => combinedEdgeData(group.source, group.target, group.evidences));
}

function baseNodeSizeForData(data) {
    if (data?.isPrimary || data?.isFocus) {
        return 112;
    }

    if (data?.type === 'person') {
        return 78;
    }

    if (data?.type === 'candidate') {
        return 42;
    }

    return 46;
}

function baseNodeFontSizeForData(data) {
    if (data?.isPrimary || data?.isFocus) {
        return 14;
    }

    if (data?.type === 'person') {
        return 12;
    }

    return 10;
}

function visualBaselineForNode(node) {
    return {
        size: baseNodeSizeForData(node.data()),
        fontSize: baseNodeFontSizeForData(node.data()),
    };
}

function applyVisualSettings(root, cy, nodes = null) {
    const state = instances.get(root);
    const iconScale = normalizedScale(state?.nodeSizeScale, 1, 0.5, 5);
    const variance = normalizedScale(state?.nodeSizeVariance, 1, 0, 4);
    const targetNodes = nodes || cy.nodes();
    const degreeNodes = cy.nodes().not('.network-filtered');
    const maxDegree = Math.max(1, ...degreeNodes.map((node) => visibleDegreeForState(node, state)));

    targetNodes.forEach((node) => {
        const baseline = visualBaselineForNode(node);
        const degreeRatio = Math.max(0, Math.min(1, visibleDegreeForState(node, state) / maxDegree));
        const typeBonus = node.data('isFocus') || node.data('isPrimary')
            ? 0
            : (node.data('type') === 'person' ? 70 : (node.data('type') === 'candidate' ? 48 : 62));
        const variedSize = baseline.size + (typeBonus * degreeRatio * variance);
        const variedFontSize = baseline.fontSize + (Math.min(5, typeBonus / 14) * degreeRatio * Math.min(2.6, variance));
        const renderSize = Math.round(Math.max(24, Math.min(620, variedSize * iconScale)));
        const renderFontSize = Math.round(Math.max(9, Math.min(34, variedFontSize * Math.max(0.82, Math.min(1.45, iconScale)))));
        const renderTextMaxWidth = Math.round(Math.max(92, Math.min(430, 96 + ((renderSize - 46) * 0.42))));

        node.data({
            renderNodeSize: renderSize,
            renderNodeFontSize: renderFontSize,
            renderTextMaxWidth,
        });
    });

    schedulePublicBadgeUpdate(root, cy);
    scheduleThreeRender(root);
}

function nodeColorFor3D(data) {
    if (data?.isPrimary || data?.isFocus) {
        return 0xf59e0b;
    }

    if (data?.type === 'person') {
        return 0x0f172a;
    }

    if (visibilityValue(data) === 'public') {
        return 0x22c55e;
    }

    if (data?.type === 'candidate') {
        return 0xf59e0b;
    }

    return 0x38bdf8;
}

function edgeColorFor3D(data) {
    const color = String(data?.lineColor || '').trim();

    if (/^#[0-9a-f]{6}$/i.test(color)) {
        return Number.parseInt(color.slice(1), 16);
    }

    return 0x94a3b8;
}

function fitTextureCover(THREE, texture) {
    const width = Number(texture?.image?.naturalWidth || texture?.image?.width || 0);
    const height = Number(texture?.image?.naturalHeight || texture?.image?.height || 0);

    if (!width || !height || width === height) {
        return texture;
    }

    texture.wrapS = THREE.ClampToEdgeWrapping;
    texture.wrapT = THREE.ClampToEdgeWrapping;

    if (width > height) {
        const repeatX = height / width;
        texture.repeat.set(repeatX, 1);
        texture.offset.set((1 - repeatX) / 2, 0);
    } else {
        const repeatY = width / height;
        texture.repeat.set(1, repeatY);
        texture.offset.set(0, (1 - repeatY) / 2);
    }

    return texture;
}

function disposeThreeObject(object) {
    object?.traverse?.((child) => {
        child.geometry?.dispose?.();

        if (Array.isArray(child.material)) {
            child.material.forEach((material) => {
                material.map?.dispose?.();
                material.dispose?.();
            });
        } else {
            child.material?.map?.dispose?.();
            child.material?.dispose?.();
        }
    });
}

function destroyThreeScene(state) {
    const threeState = state?.threeState;

    if (!threeState) {
        return;
    }

    window.cancelAnimationFrame(threeState.frame);
    window.removeEventListener('resize', threeState.resize);
    threeState.container.removeEventListener('pointerdown', threeState.pointerDown);
    threeState.container.removeEventListener('pointermove', threeState.pointerMove);
    threeState.container.removeEventListener('pointerup', threeState.pointerUp);
    threeState.container.removeEventListener('pointerleave', threeState.pointerUp);
    threeState.container.removeEventListener('wheel', threeState.wheel);
    threeState.container.removeEventListener('contextmenu', threeState.contextMenu);
    disposeThreeObject(threeState.scene);
    threeState.renderer.dispose();
    threeState.renderer.domElement.remove();
    state.threeState = null;
}

function threeNodePosition(node, index, total, maxDegree, state) {
    if (node.data('isPrimary') || node.data('isFocus')) {
        return { x: 0, y: 0, z: 0 };
    }

    const spacing = normalizedScale(state?.layoutSpacingScale, 1, 0.5, 5);
    const degreeRatio = Math.max(0, Math.min(1, visibleDegreeForState(node, state) / Math.max(1, maxDegree)));
    const hash = Number.parseInt(stableHash(node.id()).slice(-6), 36) || index + 1;
    const goldenAngle = Math.PI * (3 - Math.sqrt(5));
    const ordinal = index + 0.5;
    const inclination = Math.acos(1 - (2 * ordinal / Math.max(1, total)));
    const azimuth = (ordinal * goldenAngle) + ((hash % 720) * (Math.PI / 360));
    const radialJitter = ((hash % 100) / 100) * 124;
    const typeOffset = node.data('type') === 'person' ? -72 : (node.data('type') === 'candidate' ? 78 : 0);
    const radius = (420 + radialJitter + typeOffset - (degreeRatio * 90)) * Math.max(0.82, Math.min(2.4, spacing));

    return {
        x: Math.sin(inclination) * Math.cos(azimuth) * radius,
        y: Math.cos(inclination) * radius,
        z: Math.sin(inclination) * Math.sin(azimuth) * radius,
    };
}

function edgeWeightFor3D(edge) {
    const state = String(edge.data('connectionState') || edge.data('networkType') || '').toLowerCase();

    if (state.includes('mutual')) {
        return 4;
    }

    if (state.includes('following') || state.includes('known')) {
        return 2.8;
    }

    if (state.includes('reconstructed')) {
        return 1.55;
    }

    if (state.includes('suggestion')) {
        return 1.15;
    }

    return 1;
}

function focusNodeFor3D(nodes) {
    return nodes.find((node) => node.data('isPrimary') || node.data('isFocus'))
        || nodes.find((node) => node.data('type') === 'person')
        || nodes[0]
        || null;
}

function activeThreeFocusNode(nodes, state) {
    const requestedFocusId = String(state?.threeFocusNodeId || '').trim();

    if (requestedFocusId) {
        const focused = nodes.find((node) => node.id() === requestedFocusId);

        if (focused) {
            return focused;
        }
    }

    return focusNodeFor3D(nodes);
}

function threeGraphTopology(nodes, edges) {
    const adjacency = new Map(nodes.map((node) => [node.id(), new Map()]));

    edges.forEach((edge) => {
        const source = edge.source().id();
        const target = edge.target().id();

        if (!adjacency.has(source) || !adjacency.has(target) || source === target) {
            return;
        }

        const weight = edgeWeightFor3D(edge);
        adjacency.get(source).set(target, (adjacency.get(source).get(target) || 0) + weight);
        adjacency.get(target).set(source, (adjacency.get(target).get(source) || 0) + weight);
    });

    return adjacency;
}

function threeDistancesFromFocus(adjacency, focusId) {
    const distances = new Map([[focusId, 0]]);
    const queue = [focusId];

    for (let index = 0; index < queue.length; index += 1) {
        const current = queue[index];
        const nextDistance = distances.get(current) + 1;

        adjacency.get(current)?.forEach((_, neighborId) => {
            if (distances.has(neighborId)) {
                return;
            }

            distances.set(neighborId, nextDistance);
            queue.push(neighborId);
        });
    }

    return distances;
}

function threeClusterGroups(nodes, adjacency, focusId) {
    const lookup = new Map(nodes.map((node) => [node.id(), node]));
    const visited = new Set([focusId]);
    const groups = [];

    nodes.forEach((node) => {
        if (visited.has(node.id())) {
            return;
        }

        const group = [];
        const queue = [node.id()];
        visited.add(node.id());

        for (let index = 0; index < queue.length; index += 1) {
            const nodeId = queue[index];
            const item = lookup.get(nodeId);

            if (item) {
                group.push(item);
            }

            adjacency.get(nodeId)?.forEach((_, neighborId) => {
                if (neighborId === focusId || visited.has(neighborId)) {
                    return;
                }

                visited.add(neighborId);
                queue.push(neighborId);
            });
        }

        if (group.length) {
            groups.push(group);
        }
    });

    return groups.sort((left, right) => right.length - left.length);
}

function orthonormalBasis(direction) {
    const fallback = Math.abs(direction.y) > 0.82
        ? { x: 1, y: 0, z: 0 }
        : { x: 0, y: 1, z: 0 };
    const tangentA = {
        x: (fallback.y * direction.z) - (fallback.z * direction.y),
        y: (fallback.z * direction.x) - (fallback.x * direction.z),
        z: (fallback.x * direction.y) - (fallback.y * direction.x),
    };
    const lengthA = Math.hypot(tangentA.x, tangentA.y, tangentA.z) || 1;
    tangentA.x /= lengthA;
    tangentA.y /= lengthA;
    tangentA.z /= lengthA;
    const tangentB = {
        x: (direction.y * tangentA.z) - (direction.z * tangentA.y),
        y: (direction.z * tangentA.x) - (direction.x * tangentA.z),
        z: (direction.x * tangentA.y) - (direction.y * tangentA.x),
    };

    return { tangentA, tangentB };
}

function vectorAdd(a, b) {
    return { x: a.x + b.x, y: a.y + b.y, z: a.z + b.z };
}

function vectorScale(vector, scale) {
    return { x: vector.x * scale, y: vector.y * scale, z: vector.z * scale };
}

function threeClusterDirection(index, total) {
    const ordinal = index + 0.5;
    const goldenAngle = Math.PI * (3 - Math.sqrt(5));
    const inclination = Math.acos(1 - (2 * ordinal / Math.max(1, total)));
    const azimuth = ordinal * goldenAngle;

    return {
        x: Math.sin(inclination) * Math.cos(azimuth),
        y: Math.cos(inclination),
        z: Math.sin(inclination) * Math.sin(azimuth),
    };
}

function threeNetworkPositions(nodes, edges, state) {
    const focus = focusNodeFor3D(nodes);
    const positions = new Map();

    if (!focus) {
        return positions;
    }

    const spacing = normalizedScale(state?.layoutSpacingScale, 1, 0.5, 5);
    const adjacency = threeGraphTopology(nodes, edges);
    const distances = threeDistancesFromFocus(adjacency, focus.id());
    const groups = threeClusterGroups(nodes, adjacency, focus.id());

    positions.set(focus.id(), { x: 0, y: 0, z: 0 });

    groups.forEach((group, groupIndex) => {
        const direction = threeClusterDirection(groupIndex, groups.length);
        const { tangentA, tangentB } = orthonormalBasis(direction);
        const minDistance = Math.min(...group.map((node) => distances.get(node.id()) ?? 5));
        const clusterRadius = (255 + ((Math.max(1, minDistance) - 1) * 172) + (Math.sqrt(group.length) * 44)) * Math.max(0.82, Math.min(2.35, spacing));
        const localRadius = Math.max(70, Math.min(280, 42 + (Math.sqrt(group.length) * 38))) * Math.max(0.9, Math.min(1.9, spacing));

        group
            .sort((left, right) => {
                const leftFocusWeight = adjacency.get(left.id())?.get(focus.id()) || 0;
                const rightFocusWeight = adjacency.get(right.id())?.get(focus.id()) || 0;

                return (distances.get(left.id()) ?? 99) - (distances.get(right.id()) ?? 99)
                    || rightFocusWeight - leftFocusWeight
                    || visibleDegreeForState(right, state) - visibleDegreeForState(left, state);
            })
            .forEach((node, nodeIndex) => {
                const localOrdinal = nodeIndex + 0.5;
                const localAngle = localOrdinal * Math.PI * (3 - Math.sqrt(5));
                const localSpread = localRadius * Math.sqrt(localOrdinal / Math.max(1, group.length));
                const focusWeight = adjacency.get(node.id())?.get(focus.id()) || 0;
                const distance = distances.get(node.id()) ?? 4;
                const radialOffset = ((distance - 1) * 76) - (focusWeight * 24);
                const depthOffset = (((nodeIndex % 7) - 3) * 34) + (((Number.parseInt(stableHash(node.id()).slice(-3), 36) || 0) % 46) - 23);
                let position = vectorScale(direction, clusterRadius + radialOffset + depthOffset);
                position = vectorAdd(position, vectorScale(tangentA, Math.cos(localAngle) * localSpread));
                position = vectorAdd(position, vectorScale(tangentB, Math.sin(localAngle) * localSpread));

                positions.set(node.id(), position);
            });
    });

    nodes.forEach((node, index) => {
        if (!positions.has(node.id())) {
            positions.set(node.id(), threeNodePosition(node, index, nodes.length, 1, state));
        }
    });

    return positions;
}

function drawRoundedRect(context, x, y, width, height, radius) {
    context.beginPath();

    if (typeof context.roundRect === 'function') {
        context.roundRect(x, y, width, height, radius);
    } else {
        context.moveTo(x + radius, y);
        context.lineTo(x + width - radius, y);
        context.quadraticCurveTo(x + width, y, x + width, y + radius);
        context.lineTo(x + width, y + height - radius);
        context.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
        context.lineTo(x + radius, y + height);
        context.quadraticCurveTo(x, y + height, x, y + height - radius);
        context.lineTo(x, y + radius);
        context.quadraticCurveTo(x, y, x + radius, y);
    }

    context.closePath();
}

function focusMetaLines(node, state) {
    const data = node.data();
    const handle = data.handle || data.username || data.type || '';
    const role = data.role || '';
    const degree = visibleDegreeForState(node, state);
    const visibility = visibilityLabel(data);
    const firstLine = [handle, visibility].filter(Boolean).join(' - ');
    const secondLine = [
        `${degree.toLocaleString('de-DE')} sichtbare Verbindungen`,
        role,
    ].filter(Boolean).join(' - ');

    return [firstLine, secondLine].filter(Boolean);
}

function labelSprite(THREE, text, color = '#f8fafc', lines = []) {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    const label = truncate(text, 22);
    const subLines = (lines || []).map((line) => truncate(line, 42)).slice(0, 2);
    const hasMeta = subLines.length > 0;

    canvas.width = hasMeta ? 640 : 384;
    canvas.height = hasMeta ? 160 : 96;
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillStyle = 'rgba(15, 23, 42, 0.72)';
    drawRoundedRect(context, 18, 18, canvas.width - 36, canvas.height - 36, 20);
    context.fill();
    context.fillStyle = color;
    context.font = hasMeta ? '800 32px Arial, sans-serif' : '700 30px Arial, sans-serif';
    context.fillText(label, canvas.width / 2, hasMeta ? 52 : 50, canvas.width - 72);

    if (hasMeta) {
        context.font = '600 20px Arial, sans-serif';
        context.fillStyle = 'rgba(226, 232, 240, 0.96)';
        subLines.forEach((line, index) => {
            context.fillText(line, canvas.width / 2, 92 + (index * 28), canvas.width - 84);
        });
    }

    const texture = new THREE.CanvasTexture(canvas);
    const material = new THREE.SpriteMaterial({ map: texture, transparent: true, depthWrite: false });
    const sprite = new THREE.Sprite(material);
    sprite.scale.set(hasMeta ? 162 : 92, hasMeta ? 40 : 23, 1);

    return sprite;
}

function visibleThreeData(cy) {
    const nodes = cy.nodes().not('.network-filtered').toArray();
    const visibleNodeIds = new Set(nodes.map((node) => node.id()));
    const edges = cy.edges().not('.network-filtered')
        .filter((edge) => visibleNodeIds.has(edge.source().id()) && visibleNodeIds.has(edge.target().id()))
        .toArray();

    return { nodes, edges, visibleNodeIds };
}

function curvedAvatarGeometry(THREE, radius) {
    const rings = 7;
    const segments = 40;
    const curveDepth = radius * 0.34;
    const vertices = [0, 0, curveDepth];
    const uvs = [0.5, 0.5];
    const indices = [];

    for (let ring = 1; ring <= rings; ring += 1) {
        const ringRadius = radius * (ring / rings);
        const normalized = ringRadius / radius;
        const z = curveDepth * (1 - (normalized * normalized));

        for (let segment = 0; segment < segments; segment += 1) {
            const angle = (segment / segments) * Math.PI * 2;
            const x = Math.cos(angle) * ringRadius;
            const y = Math.sin(angle) * ringRadius;
            vertices.push(x, y, z);
            uvs.push(0.5 + (x / (radius * 2)), 0.5 + (y / (radius * 2)));
        }
    }

    for (let segment = 0; segment < segments; segment += 1) {
        const current = 1 + segment;
        const next = 1 + ((segment + 1) % segments);
        indices.push(0, current, next);
    }

    for (let ring = 2; ring <= rings; ring += 1) {
        const previousStart = 1 + ((ring - 2) * segments);
        const currentStart = 1 + ((ring - 1) * segments);

        for (let segment = 0; segment < segments; segment += 1) {
            const previousCurrent = previousStart + segment;
            const previousNext = previousStart + ((segment + 1) % segments);
            const current = currentStart + segment;
            const next = currentStart + ((segment + 1) % segments);
            indices.push(previousCurrent, current, next, previousCurrent, next, previousNext);
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();

    return geometry;
}

function createProfileAvatarMesh(THREE, threeState, node, radius, position) {
    const imageUrl = String(node.data('imageUrl') || '').trim();

    if (!imageUrl) {
        return null;
    }

    const geometry = curvedAvatarGeometry(THREE, radius * 1.34);
    const material = new THREE.MeshBasicMaterial({
        color: 0xffffff,
        transparent: true,
        opacity: 0,
        depthWrite: false,
        side: THREE.DoubleSide,
    });
    const avatar = new THREE.Mesh(geometry, material);
    const outward = position.clone().normalize();

    if (!Number.isFinite(outward.x) || outward.lengthSq() < 0.01) {
        outward.set(0, 0, 1);
    }

    avatar.position.copy(position).add(outward.multiplyScalar(radius * 0.72));
    avatar.userData.nodeId = node.id();
    avatar.userData.anchorId = node.id();
    avatar.userData.kind = 'avatar';
    avatar.userData.offsetRadius = radius * 1.05;
    avatar.userData.verticalOffset = 0;
    threeState.textureLoader.load(
        imageUrl,
        (texture) => {
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.anisotropy = Math.min(8, threeState.renderer.capabilities.getMaxAnisotropy?.() || 1);
            material.map = fitTextureCover(THREE, texture);
            material.opacity = 1;
            material.needsUpdate = true;
        },
        undefined,
        () => {
            material.opacity = 0;
        },
    );

    return avatar;
}

function pauseThreeAutoRotate(threeState, duration = 120000) {
    if (threeState) {
        threeState.autoRotatePausedUntil = Date.now() + duration;
    }
}

function pickThreeNode(root, state, event) {
    const threeState = state?.threeState;

    if (!threeState) {
        return null;
    }

    const rect = threeState.renderer.domElement.getBoundingClientRect();
    const pointer = new threeState.THREE.Vector2(
        ((event.clientX - rect.left) / Math.max(1, rect.width)) * 2 - 1,
        -(((event.clientY - rect.top) / Math.max(1, rect.height)) * 2 - 1),
    );

    threeState.raycaster.setFromCamera(pointer, threeState.camera);
    const hit = threeState.raycaster
        .intersectObjects(threeState.pickables, false)
        .find((item) => item.object?.userData?.nodeId);

    if (!hit) {
        return null;
    }

    const node = state.cy.getElementById(hit.object.userData.nodeId);

    return node.length ? node : null;
}

function easeInOutCubic(progress) {
    const value = Math.max(0, Math.min(1, progress));

    return value < 0.5
        ? 4 * value * value * value
        : 1 - Math.pow(-2 * value + 2, 3) / 2;
}

function syncThreeEdges(threeState) {
    threeState?.edgeLines?.forEach((line) => {
        const source = threeState.nodeGroups?.get(line.userData.sourceId);
        const target = threeState.nodeGroups?.get(line.userData.targetId);

        if (!source || !target) {
            return;
        }

        const attribute = line.geometry.getAttribute('position');
        attribute.setXYZ(0, source.position.x, source.position.y, source.position.z);
        attribute.setXYZ(1, target.position.x, target.position.y, target.position.z);
        attribute.needsUpdate = true;
    });
}

function syncThreeBillboards(threeState) {
    if (!threeState?.billboards?.length) {
        return;
    }

    const cameraLocal = threeState.group.worldToLocal(threeState.camera.position.clone());
    const up = new threeState.THREE.Vector3(0, 1, 0);

    threeState.billboards.forEach((billboard) => {
        const anchor = threeState.nodeGroups?.get(billboard.userData.anchorId);

        if (!anchor) {
            return;
        }

        const direction = cameraLocal.clone().sub(anchor.position);

        if (direction.lengthSq() < 0.01) {
            direction.set(0, 0, 1);
        } else {
            direction.normalize();
        }

        billboard.position.copy(anchor.position)
            .add(direction.multiplyScalar(Number(billboard.userData.offsetRadius || 0)))
            .add(up.clone().multiplyScalar(Number(billboard.userData.verticalOffset || 0)));
        billboard.lookAt(threeState.camera.position);
    });
}

function updateThreePositionTweens(threeState) {
    if (!threeState?.positionTweens?.length) {
        return;
    }

    const now = performance.now();
    threeState.positionTweens = threeState.positionTweens.filter((tween) => {
        const progress = easeInOutCubic((now - tween.startedAt) / tween.duration);
        tween.group.position.lerpVectors(tween.from, tween.to, progress);

        return progress < 1;
    });

    syncThreeEdges(threeState);
    syncThreeBillboards(threeState);
}

function updateThreeCameraTween(threeState) {
    if (!threeState?.cameraTween) {
        return;
    }

    const tween = threeState.cameraTween;
    const progress = easeInOutCubic((performance.now() - tween.startedAt) / tween.duration);
    threeState.camera.position.lerpVectors(tween.from, tween.to, progress);

    if (progress >= 1) {
        threeState.cameraTween = null;
    }
}

function cancelThreeCameraTween(threeState) {
    if (threeState) {
        threeState.cameraTween = null;
    }
}

function flyThreeCameraToNode(state, nodeId) {
    const threeState = state?.threeState;
    const nodeGroup = threeState?.nodeGroups?.get(nodeId);

    if (!threeState || !nodeGroup) {
        return;
    }

    const THREE = threeState.THREE;
    const worldPosition = nodeGroup.getWorldPosition(new THREE.Vector3());
    const current = threeState.camera.position.clone();
    const targetDistance = Math.max(440, Math.min(760, Math.abs(current.z - worldPosition.z) || 560));
    const target = new THREE.Vector3(
        worldPosition.x,
        worldPosition.y,
        Math.max(320, Math.min(2400, worldPosition.z + targetDistance)),
    );

    threeState.cameraTween = {
        from: current,
        to: target,
        startedAt: performance.now(),
        duration: 760,
    };
}

function setThreeFocusNode(root, state, nodeId) {
    if (!state || state.viewMode !== '3d' || !nodeId) {
        return;
    }

    const focusChanged = state.threeFocusNodeId !== nodeId;
    state.threeFocusNodeId = nodeId;
    pauseThreeAutoRotate(state.threeState, Number.POSITIVE_INFINITY);
    flyThreeCameraToNode(state, nodeId);

    if (focusChanged) {
        scheduleThreeRender(root);
    }
}

function rebuildThreeGraph(root, state) {
    const threeState = state?.threeState;

    if (!threeState || !state?.cy) {
        return;
    }

    const { THREE, group, cy } = threeState;
    disposeThreeObject(group);
    group.clear();
    threeState.pickables = [];
    threeState.billboards = [];
    threeState.edgeLines = [];
    threeState.nodeGroups = new Map();
    threeState.positionTweens = [];

    const { nodes, edges } = visibleThreeData(cy);
    const maxDegree = Math.max(1, ...nodes.map((node) => visibleDegreeForState(node, state)));
    const layoutPositions = threeNetworkPositions(nodes, edges, state);
    const focusNode = activeThreeFocusNode(nodes, state);
    const focusId = focusNode?.id();

    nodes.forEach((node, index) => {
        const coordinates = layoutPositions.get(node.id()) || threeNodePosition(node, index, nodes.length, maxDegree, state);
        const targetPosition = new THREE.Vector3(coordinates.x, coordinates.y, coordinates.z);
        const degreeRatio = visibleDegreeForState(node, state) / maxDegree;
        const radius = Math.max(3.2, Math.min(28, (Number(node.data('renderNodeSize')) || baseNodeSizeForData(node.data())) / 10));
        const renderRadius = radius * (0.8 + (degreeRatio * 0.45));
        const nodeGroup = new THREE.Group();
        nodeGroup.position.copy(targetPosition);

        const geometry = new THREE.SphereGeometry(radius * (0.8 + (degreeRatio * 0.45)), 28, 18);
        const material = new THREE.MeshStandardMaterial({
            color: node.id() === focusId ? 0xfbbf24 : nodeColorFor3D(node.data()),
            roughness: 0.45,
            metalness: 0.12,
        });
        const mesh = new THREE.Mesh(geometry, material);
        mesh.userData.nodeId = node.id();
        mesh.userData.kind = 'node';
        nodeGroup.add(mesh);
        group.add(nodeGroup);
        threeState.nodeGroups.set(node.id(), nodeGroup);
        threeState.pickables.push(mesh);

        const avatar = createProfileAvatarMesh(THREE, threeState, node, renderRadius, targetPosition);

        if (avatar) {
            group.add(avatar);
            threeState.pickables.push(avatar);
            threeState.billboards.push(avatar);
        }

        if (node.id() === focusId || node.data('isPrimary') || node.data('isFocus') || visibleDegreeForState(node, state) >= 3) {
            const label = labelSprite(
                THREE,
                node.data('fullLabel') || node.data('label'),
                node.id() === focusId ? '#fef3c7' : '#f8fafc',
                node.id() === focusId ? focusMetaLines(node, state) : [],
            );
            label.userData.anchorId = node.id();
            label.userData.offsetRadius = renderRadius + (node.id() === focusId ? 46 : 26);
            label.userData.verticalOffset = node.id() === focusId ? renderRadius + 18 : renderRadius + 12;
            group.add(label);
            threeState.billboards.push(label);
        }
    });

    edges.forEach((edge) => {
        const from = threeState.nodeGroups.get(edge.source().id())?.position;
        const to = threeState.nodeGroups.get(edge.target().id())?.position;

        if (!from || !to) {
            return;
        }

        const geometry = new THREE.BufferGeometry().setFromPoints([from, to]);
        const material = new THREE.LineBasicMaterial({
            color: edgeColorFor3D(edge.data()),
            transparent: true,
            opacity: Math.max(0.22, Math.min(0.82, Number(edge.data('edgeOpacity')) || 0.48)),
        });
        const line = new THREE.Line(geometry, material);
        line.userData.sourceId = edge.source().id();
        line.userData.targetId = edge.target().id();
        group.add(line);
        threeState.edgeLines.push(line);
    });

    syncThreeEdges(threeState);
    syncThreeBillboards(threeState);
    threeState.needsRender = true;
}

async function ensureThreeScene(root, state) {
    if (state?.threeState) {
        return state.threeState;
    }

    const container = root.querySelector('[data-network-3d-canvas]');

    if (!container || !state?.cy) {
        return null;
    }

    const THREE = await loadThree();

    if (state.viewMode !== '3d') {
        return null;
    }

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x020617);
    const camera = new THREE.PerspectiveCamera(54, 1, 1, 10000);
    camera.position.set(0, 0, 980);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.domElement.className = 'h-full w-full';
    renderer.domElement.style.display = 'block';
    container.replaceChildren(renderer.domElement);

    const group = new THREE.Group();
    const raycaster = new THREE.Raycaster();
    const textureLoader = new THREE.TextureLoader();
    textureLoader.setCrossOrigin('anonymous');
    scene.add(group);
    scene.add(new THREE.AmbientLight(0xffffff, 0.78));
    const light = new THREE.DirectionalLight(0xffffff, 1.15);
    light.position.set(240, 320, 520);
    scene.add(light);

    const resize = () => {
        const rect = container.getBoundingClientRect();
        const width = Math.max(1, Math.floor(rect.width));
        const height = Math.max(1, Math.floor(rect.height));
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        renderer.setSize(width, height, false);
    };
    let dragging = false;
    let lastX = 0;
    let lastY = 0;
    let pointerStartX = 0;
    let pointerStartY = 0;
    let pointerMoved = false;
    let lastTap = null;
    let interactionMode = 'rotate';
    const pointerDown = (event) => {
        dragging = true;
        interactionMode = event.shiftKey || event.button === 1 || event.button === 2 ? 'pan' : 'rotate';
        lastX = event.clientX;
        lastY = event.clientY;
        pointerStartX = event.clientX;
        pointerStartY = event.clientY;
        pointerMoved = false;
        pauseThreeAutoRotate(state.threeState);
        cancelThreeCameraTween(state.threeState);
        container.setPointerCapture?.(event.pointerId);
    };
    const pointerMove = (event) => {
        if (!dragging) {
            return;
        }

        const dx = event.clientX - lastX;
        const dy = event.clientY - lastY;
        const totalDx = event.clientX - pointerStartX;
        const totalDy = event.clientY - pointerStartY;

        if (Math.hypot(totalDx, totalDy) > 5) {
            pointerMoved = true;
            pauseThreeAutoRotate(state.threeState);
        }

        if (interactionMode === 'pan') {
            const panScale = Math.max(0.45, Math.min(3.5, camera.position.z / 780));
            camera.position.x -= dx * panScale;
            camera.position.y += dy * panScale;
        } else {
            group.rotation.y += dx * 0.006;
            group.rotation.x += dy * 0.004;
        }

        lastX = event.clientX;
        lastY = event.clientY;
    };
    const pointerUp = (event) => {
        dragging = false;

        if (pointerMoved) {
            pauseThreeAutoRotate(state.threeState);
            return;
        }

        const node = pickThreeNode(root, state, event);

        if (!node) {
            setSelected(root, state.cy, null);
            return;
        }

        const now = Date.now();
        const isDoubleTap = lastTap?.id === node.id() && now - lastTap.at <= 360;
        const rect = container.getBoundingClientRect();
        const menuX = Math.min(window.innerWidth - 240, Math.max(8, rect.left + event.clientX - rect.left + 12));
        const menuY = Math.min(window.innerHeight - 190, Math.max(8, rect.top + event.clientY - rect.top + 12));
        setSelected(root, state.cy, node.id());
        lastTap = { id: node.id(), at: now };

        if (isDoubleTap) {
            lastTap = null;
            dispatchOpenNode(root, node);
            return;
        }

        dispatchNodeMenu(root, node, menuX, menuY);
    };
    const wheel = (event) => {
        event.preventDefault();
        pauseThreeAutoRotate(state.threeState);
        cancelThreeCameraTween(state.threeState);
        camera.position.z = Math.max(260, Math.min(2200, camera.position.z + (event.deltaY * 0.8)));
    };
    const contextMenu = (event) => {
        event.preventDefault();
    };
    const animate = () => {
        const autoRotatePaused = Date.now() < (state.threeState?.autoRotatePausedUntil || 0);

        if (!dragging && !autoRotatePaused) {
            group.rotation.y += 0.0014;
        }

        updateThreeCameraTween(state.threeState);
        updateThreePositionTweens(state.threeState);
        syncThreeBillboards(state.threeState);
        renderer.render(scene, camera);
        state.threeState.frame = window.requestAnimationFrame(animate);
    };

    const threeState = {
        THREE,
        container,
        scene,
        camera,
        renderer,
        group,
        raycaster,
        textureLoader,
        pickables: [],
        billboards: [],
        nodeGroups: new Map(),
        edgeLines: [],
        positionTweens: [],
        cameraTween: null,
        autoRotatePausedUntil: 0,
        resize,
        pointerDown,
        pointerMove,
        pointerUp,
        wheel,
        contextMenu,
        frame: 0,
        cy: state.cy,
    };

    state.threeState = threeState;
    window.addEventListener('resize', resize);
    container.addEventListener('pointerdown', pointerDown);
    container.addEventListener('pointermove', pointerMove);
    container.addEventListener('pointerup', pointerUp);
    container.addEventListener('pointerleave', pointerUp);
    container.addEventListener('wheel', wheel, { passive: false });
    container.addEventListener('contextmenu', contextMenu);
    resize();
    rebuildThreeGraph(root, state);
    threeState.frame = window.requestAnimationFrame(animate);

    return threeState;
}

function scheduleThreeRender(root) {
    const state = instances.get(root);

    if (!state || state.viewMode !== '3d' || state.threeRenderQueued) {
        return;
    }

    state.threeRenderQueued = true;
    window.requestAnimationFrame(() => {
        state.threeRenderQueued = false;
        rebuildThreeGraph(root, state);
    });
}

async function setViewMode(root, state, mode) {
    if (!state) {
        return;
    }

    state.viewMode = normalizeViewMode(mode);
    root.dataset.networkViewMode = state.viewMode;
    writeStoredViewMode(root, state.viewMode);
    updateViewModeControls(root, state);

    const canvas = root.querySelector('[data-network-canvas]');
    const threeCanvas = root.querySelector('[data-network-3d-canvas]');
    const overlays = root.querySelector('[data-network-profile-overlays]');

    if (state.viewMode === '3d') {
        canvas?.classList.add('hidden');
        overlays?.classList.add('hidden');
        threeCanvas?.classList.remove('hidden');
        await ensureThreeScene(root, state);
        scheduleThreeRender(root);
        return;
    }

    threeCanvas?.classList.add('hidden');
    canvas?.classList.remove('hidden');
    overlays?.classList.remove('hidden');
    destroyThreeScene(state);
    state.cy.resize();
    fitGraph(state.cy, { tight: true });
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
        layoutSpacingScale: state.layoutSpacingScale,
        nodeSizeScale: state.nodeSizeScale,
        nodeSizeVariance: state.nodeSizeVariance,
    }));
}

function normalizedScale(value, fallback, min, max) {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return fallback;
    }

    return Math.max(min, Math.min(max, number));
}

function percentLabel(scale) {
    return `${Math.round(Number(scale || 1) * 100)}%`;
}

function controlScaleValue(control, fallback, min, max) {
    return normalizedScale((Number(control?.value) || Math.round(fallback * 100)) / 100, fallback, min, max);
}

function normalizeLayoutMode(value) {
    const mode = String(value || '').trim();

    return LAYOUT_MODES.has(mode) ? mode : DEFAULT_LAYOUT_MODE;
}

function normalizeViewMode(value) {
    return String(value || '').trim() === '3d' ? '3d' : '2d';
}

function viewModeStorageKey(root) {
    return `network-map-view-mode:${root.dataset.networkFilterScope || root.dataset.networkMapId || 'default'}`;
}

function readStoredViewMode(root) {
    try {
        return normalizeViewMode(localStorage.getItem(viewModeStorageKey(root)) || root.dataset.networkViewMode);
    } catch {
        return normalizeViewMode(root.dataset.networkViewMode);
    }
}

function writeStoredViewMode(root, mode) {
    try {
        localStorage.setItem(viewModeStorageKey(root), normalizeViewMode(mode));
    } catch {
        // localStorage can be unavailable in hardened browser contexts.
    }
}

function layoutModeStorageKey(root) {
    return `network-map-layout-mode:${root.dataset.networkFilterScope || root.dataset.networkMapId || 'default'}`;
}

function readStoredLayoutMode(root) {
    try {
        return normalizeLayoutMode(localStorage.getItem(layoutModeStorageKey(root)) || root.dataset.networkLayoutMode);
    } catch {
        return normalizeLayoutMode(root.dataset.networkLayoutMode);
    }
}

function writeStoredLayoutMode(root, mode) {
    try {
        localStorage.setItem(layoutModeStorageKey(root), normalizeLayoutMode(mode));
    } catch {
        // localStorage can be unavailable in hardened browser contexts.
    }
}

function layoutSettingsStorageKey(root) {
    return `network-map-layout-settings:${root.dataset.networkFilterScope || root.dataset.networkMapId || 'default'}`;
}

function readStoredLayoutSettings(root) {
    try {
        return JSON.parse(localStorage.getItem(layoutSettingsStorageKey(root)) || '{}') || {};
    } catch {
        return {};
    }
}

function writeStoredLayoutSettings(root, state) {
    try {
        localStorage.setItem(layoutSettingsStorageKey(root), JSON.stringify({
            layoutSpacingScale: normalizedScale(state?.layoutSpacingScale, 1, 0.5, 5),
            nodeSizeScale: normalizedScale(state?.nodeSizeScale, 1, 0.5, 5),
            nodeSizeVariance: normalizedScale(state?.nodeSizeVariance, 1, 0, 4),
        }));
    } catch {
        // Ignore storage errors.
    }
}

function updateLayoutControls(root, state) {
    root.querySelectorAll('[data-network-layout-mode]').forEach((control) => {
        control.value = normalizeLayoutMode(state?.layoutMode);
    });

    root.querySelectorAll('[data-network-layout-spacing]').forEach((control) => {
        control.value = String(Math.round(normalizedScale(state?.layoutSpacingScale, 1, 0.5, 5) * 100));
    });
    root.querySelectorAll('[data-network-layout-spacing-value]').forEach((element) => {
        element.textContent = percentLabel(normalizedScale(state?.layoutSpacingScale, 1, 0.5, 5));
    });

    root.querySelectorAll('[data-network-icon-scale]').forEach((control) => {
        control.value = String(Math.round(normalizedScale(state?.nodeSizeScale, 1, 0.5, 5) * 100));
    });
    root.querySelectorAll('[data-network-icon-scale-value]').forEach((element) => {
        element.textContent = percentLabel(normalizedScale(state?.nodeSizeScale, 1, 0.5, 5));
    });

    root.querySelectorAll('[data-network-size-variance]').forEach((control) => {
        control.value = String(Math.round(normalizedScale(state?.nodeSizeVariance, 1, 0, 4) * 100));
    });
    root.querySelectorAll('[data-network-size-variance-value]').forEach((element) => {
        element.textContent = percentLabel(normalizedScale(state?.nodeSizeVariance, 1, 0, 4));
    });
}

function updateViewModeControls(root, state) {
    const mode = normalizeViewMode(state?.viewMode);

    root.querySelectorAll('[data-network-view-mode]').forEach((button) => {
        updateButton(button, button.dataset.networkViewMode === mode);
    });

    root.querySelectorAll('[data-network-layout-mode]').forEach((control) => {
        control.disabled = mode === '3d';
        control.title = mode === '3d'
            ? 'Die 3D-Ansicht nutzt eine feste raeumliche Netzwerk-Anordnung.'
            : '';
    });
}

function rootStateForCy(cy) {
    for (const root of activeRoots) {
        const state = instances.get(root);

        if (state?.cy === cy) {
            return { root, state };
        }
    }

    return null;
}

function setLayoutStatus(root, text) {
    root.querySelectorAll('[data-network-layout-state]').forEach((element) => {
        element.textContent = text;
    });
}

function stableHash(value) {
    let hash = 2166136261;
    const text = String(value || '');

    for (let index = 0; index < text.length; index += 1) {
        hash ^= text.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }

    return (hash >>> 0).toString(36);
}

function graphSignature(cy) {
    const nodes = cy.nodes().map((node) => node.id()).sort().join(',');
    const edges = cy.edges()
        .map((edge) => `${edge.id()}:${edge.source().id()}>${edge.target().id()}:${edge.data('networkType') || ''}`)
        .sort()
        .join(',');

    return stableHash(`${nodes}|${edges}`);
}

function graphIdentity(root, state, cy) {
    const dataHash = String(state?.graphDataHash || root.dataset.networkGraphHash || '').trim();

    if (dataHash) {
        return `hash:${dataHash}`;
    }

    const token = String(state?.graphToken || root.dataset.networkGraphToken || '').trim();

    if (token) {
        return `token:${token}`;
    }

    if (!cy?.nodes?.().length) {
        return '';
    }

    return `sig:${graphSignature(cy)}`;
}

function layoutStorageKey(root, state, cy) {
    const scope = root.dataset.networkFilterScope || root.dataset.networkMapId || 'default';
    const identity = graphIdentity(root, state, cy);

    return identity ? `${LAYOUT_STORAGE_PREFIX}:${scope}:${identity}` : '';
}

function updateGraphIdentity(root, state, detail = {}) {
    state.graphToken = String(detail.token || '').trim();
    state.graphDataHash = String(detail.dataHash || detail.data_hash || '').trim();
    root.dataset.networkGraphToken = state.graphToken;
    root.dataset.networkGraphHash = state.graphDataHash;
}

function readStoredLayout(root, state, cy) {
    const key = layoutStorageKey(root, state, cy);

    if (!key) {
        return null;
    }

    try {
        const stored = JSON.parse(localStorage.getItem(key) || 'null');

        return stored && stored.version === 6 ? { key, stored } : null;
    } catch {
        return null;
    }
}

function isFinitePoint(point) {
    return Number.isFinite(Number(point?.x)) && Number.isFinite(Number(point?.y));
}

function writeStoredLayout(root, cy) {
    const state = instances.get(root);

    if (!state || state.isLoadingGraph || state.suppressLayoutSave || !cy.nodes().length) {
        return;
    }

    const key = layoutStorageKey(root, state, cy);

    if (!key) {
        return;
    }

    const positions = {};

    cy.nodes().forEach((node) => {
        const position = node.position();

        if (isFinitePoint(position)) {
            positions[node.id()] = {
                x: Math.round(Number(position.x) * 10) / 10,
                y: Math.round(Number(position.y) * 10) / 10,
            };
        }
    });

    try {
        localStorage.setItem(key, JSON.stringify({
            version: 6,
            savedAt: Date.now(),
            layoutMode: normalizeLayoutMode(state.layoutMode),
            layoutSpacingScale: normalizedScale(state.layoutSpacingScale, 1, 0.5, 5),
            nodeSizeScale: normalizedScale(state.nodeSizeScale, 1, 0.5, 5),
            nodeSizeVariance: normalizedScale(state.nodeSizeVariance, 1, 0, 4),
            nodeCount: cy.nodes().length,
            edgeCount: cy.edges().length,
            zoom: Math.round(cy.zoom() * 10000) / 10000,
            pan: {
                x: Math.round(cy.pan().x * 10) / 10,
                y: Math.round(cy.pan().y * 10) / 10,
            },
            positions,
        }));
        setLayoutStatus(root, 'Positionen gespeichert');
    } catch (error) {
        console.warn('Netzwerk-Layout konnte nicht gespeichert werden.', error);
        setLayoutStatus(root, 'Speichern nicht moeglich');
    }
}

function scheduleStoredLayoutSave(root, cy, delay = 420) {
    const state = instances.get(root);

    if (!state || state.isLoadingGraph || state.suppressLayoutSave || !cy.nodes().length) {
        return;
    }

    window.clearTimeout(state.layoutSaveTimer);
    state.layoutSaveTimer = window.setTimeout(() => writeStoredLayout(root, cy), delay);
}

function scheduleStoredLayoutSaveFromCy(cy, delay = 420) {
    activeRoots.forEach((root) => {
        const state = instances.get(root);

        if (state?.cy === cy) {
            scheduleStoredLayoutSave(root, cy, delay);
        }
    });
}

function applyStoredLayout(root, cy, options = {}) {
    const state = instances.get(root);
    const payload = readStoredLayout(root, state, cy);

    if (!state || !payload?.stored?.positions) {
        return false;
    }

    const entries = Object.entries(payload.stored.positions)
        .filter(([id, position]) => cy.getElementById(id).length && isFinitePoint(position));
    const minExpected = Math.max(1, Math.floor(cy.nodes().length * (options.minRatio ?? 0.75)));

    if (entries.length < minExpected) {
        return false;
    }

    state.suppressLayoutSave = true;
    state.layoutMode = normalizeLayoutMode(payload.stored.layoutMode || state.layoutMode);
    updateLayoutControls(root, state);
    writeStoredLayoutMode(root, state.layoutMode);

    cy.batch(() => {
        entries.forEach(([id, position]) => {
            cy.getElementById(id).position({
                x: Number(position.x),
                y: Number(position.y),
            });
        });
    });

    if (isFinitePoint(payload.stored.pan) && Number.isFinite(Number(payload.stored.zoom))) {
        cy.zoom(Math.max(cy.minZoom(), Math.min(cy.maxZoom(), Number(payload.stored.zoom))));
        cy.pan({
            x: Number(payload.stored.pan.x),
            y: Number(payload.stored.pan.y),
        });
    }

    window.requestAnimationFrame(() => {
        state.suppressLayoutSave = false;
        schedulePublicBadgeUpdate(root, cy);
    });

    state.layoutRestored = true;
    state.hasAppliedLayout = true;
    setLayoutStatus(root, 'Letzter Stand geladen');

    return true;
}

function clearStoredLayout(root, cy) {
    const state = instances.get(root);
    const key = layoutStorageKey(root, state, cy);

    if (!key) {
        return;
    }

    try {
        localStorage.removeItem(key);
    } catch {
        // Ignore storage errors.
    }
}

function visibleConnectedEdges(node) {
    return node.connectedEdges().not('.network-filtered');
}

function visibleDegree(node) {
    return visibleConnectedEdges(node).length;
}

function evidenceVisibleForState(evidence, state) {
    const type = evidence?.type;

    if (type === 'public-profile') {
        return state?.showPublic !== false;
    }

    if (type === 'inferred') {
        return state?.showInferred !== false;
    }

    if (type === 'tracked-list' || type === 'tracked-profile-rel') {
        return state?.showTracked !== false;
    }

    return true;
}

function edgeEvidences(edge) {
    const evidences = edge.data('edgeEvidences');

    if (Array.isArray(evidences) && evidences.length) {
        return evidences;
    }

    return [edgeEvidenceFromEdge(edge.data())];
}

function visibleEdgeEvidences(edge, state) {
    return edgeEvidences(edge).filter((evidence) => evidenceVisibleForState(evidence, state));
}

function edgeVisibleForState(edge, state) {
    return visibleEdgeEvidences(edge, state).length > 0;
}

function applyEdgeRenderState(cy, state) {
    cy.edges().forEach((edge) => {
        const evidences = visibleEdgeEvidences(edge, state);
        edge.data(edgeRenderState(edge.data('source'), edge.data('target'), evidences));
    });
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

    const focus = cy.nodes().filter((node) => Boolean(node.data('isFocus'))).first();

    if (focus.length) {
        return focus;
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
        name: node.data('fullLabel') || node.data('handle') || node.id(),
        handle: node.data('handle') || '',
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

function layoutSort(a, b) {
    return visibleDegree(b) - visibleDegree(a)
        || String(a.data('username') || a.data('handle') || a.id())
            .localeCompare(String(b.data('username') || b.data('handle') || b.id()));
}

function layoutCenter(visibleNodes, minWidth = 1200, minHeight = 820) {
    const width = Math.max(minWidth, visibleNodes.length * 22);
    const height = Math.max(minHeight, visibleNodes.length * 16);

    return { width, height, centerX: width / 2, centerY: height / 2 };
}

function primaryLayoutNode(nodes) {
    return nodes.find((node) => Boolean(node.data('isFocus')))
        || nodes.find((node) => Boolean(node.data('isPrimary')))
        || nodes.find((node) => node.data('type') === 'person')
        || nodes[0];
}

function estimatedNodeBox(node, position) {
    const size = Math.max(24, Number(node.data('renderNodeSize') || node.data('nodeSize')) || 48);
    const fontSize = Math.max(9, Number(node.data('renderNodeFontSize') || node.data('nodeFontSize')) || 11);
    const maxTextWidth = Math.max(92, Number(node.data('renderTextMaxWidth')) || 105);
    const label = String(node.data('fullLabel') || node.data('label') || node.data('handle') || node.id());
    const lines = Math.max(1, Math.ceil((label.length * fontSize * 0.54) / maxTextWidth));
    const labelWidth = Math.min(maxTextWidth + 28, Math.max(size, Math.min(maxTextWidth, label.length * fontSize * 0.54) + 18));
    const labelHeight = (lines * (fontSize + 5)) + 16;
    const margin = Math.max(44, Math.min(170, size * 0.34));
    const width = Math.max(size, labelWidth) + margin;
    const height = size + labelHeight + margin;

    return {
        left: position.x - (width / 2),
        right: position.x + (width / 2),
        top: position.y - (size / 2) - (margin * 0.42),
        bottom: position.y + (size / 2) + labelHeight + (margin * 0.58),
        width,
        height,
    };
}

function boxesOverlap(left, right) {
    return left.left < right.right
        && left.right > right.left
        && left.top < right.bottom
        && left.bottom > right.top;
}

function overlapAmount(left, right) {
    return {
        x: Math.min(left.right, right.right) - Math.max(left.left, right.left),
        y: Math.min(left.bottom, right.bottom) - Math.max(left.top, right.top),
    };
}

function updatesBounds(updates) {
    const bounds = updates.reduce((current, update) => {
        const box = estimatedNodeBox(update.node, update.position);

        return {
            left: Math.min(current.left, box.left),
            right: Math.max(current.right, box.right),
            top: Math.min(current.top, box.top),
            bottom: Math.max(current.bottom, box.bottom),
        };
    }, { left: Infinity, right: -Infinity, top: Infinity, bottom: -Infinity });

    if (!Number.isFinite(bounds.left)) {
        return { left: 0, right: 0, top: 0, bottom: 0, width: 0, height: 0, radius: 0 };
    }

    const width = bounds.right - bounds.left;
    const height = bounds.bottom - bounds.top;

    return {
        ...bounds,
        width,
        height,
        radius: Math.max(120, Math.hypot(width, height) / 2),
    };
}

function visibleNeighborNodes(node, visibleIds) {
    const neighbors = [];

    node.connectedEdges()
        .not('.network-filtered')
        .forEach((edge) => {
            const other = edge.source().id() === node.id() ? edge.target() : edge.source();

            if (visibleIds.has(other.id()) && other.id() !== node.id()) {
                neighbors.push(other);
            }
        });

    return neighbors;
}

function addNodeToLayoutGroup(group, node) {
    if (group.nodeIds.has(node.id())) {
        return;
    }

    group.nodeIds.add(node.id());
    group.nodes.push(node);
}

function groupAdjacencyKey(left, right) {
    return [left, right].sort().join('|');
}

function groupAdjacencyWeight(adjacency, left, right) {
    return adjacency.get(groupAdjacencyKey(left, right)) || 0;
}

function groupScore(group) {
    return (group.nodes.length * 8)
        + (visibleDegree(group.root) * 5)
        + group.nodes.reduce((score, node) => score + visibleDegree(node), 0);
}

function orderGroupsByAdjacency(groups, adjacency) {
    const remaining = [...groups].sort((a, b) => groupScore(b) - groupScore(a) || String(a.id).localeCompare(String(b.id)));
    const ordered = [];

    if (!remaining.length) {
        return ordered;
    }

    ordered.push(remaining.shift());

    while (remaining.length) {
        const last = ordered[ordered.length - 1];
        let bestIndex = 0;
        let bestScore = -Infinity;

        remaining.forEach((group, index) => {
            const lastWeight = groupAdjacencyWeight(adjacency, last.id, group.id);
            const placedWeight = ordered.reduce((weight, placed) => Math.max(weight, groupAdjacencyWeight(adjacency, placed.id, group.id)), 0);
            const score = (lastWeight * 40) + (placedWeight * 12) + groupScore(group);

            if (score > bestScore) {
                bestScore = score;
                bestIndex = index;
            }
        });

        ordered.push(remaining.splice(bestIndex, 1)[0]);
    }

    return ordered;
}

function connectedLayoutGroups(cy, visibleNodes, focus) {
    const visibleIds = new Set(visibleNodes.map((node) => node.id()));
    const anchors = visibleNeighborNodes(focus, visibleIds).sort(layoutSort);
    const assignments = new Map();
    const groups = new Map();
    const queue = [];

    anchors.forEach((anchor) => {
        const group = {
            id: anchor.id(),
            root: anchor,
            nodes: [],
            nodeIds: new Set(),
        };

        addNodeToLayoutGroup(group, anchor);
        groups.set(group.id, group);
        assignments.set(anchor.id(), group.id);
        queue.push(anchor);
    });

    while (queue.length) {
        const node = queue.shift();
        const groupId = assignments.get(node.id());
        const group = groups.get(groupId);

        visibleNeighborNodes(node, visibleIds)
            .sort(layoutSort)
            .forEach((neighbor) => {
                if (neighbor.id() === focus.id()) {
                    return;
                }

                if (!assignments.has(neighbor.id())) {
                    assignments.set(neighbor.id(), groupId);
                    addNodeToLayoutGroup(group, neighbor);
                    queue.push(neighbor);
                }
            });
    }

    visibleNodes
        .toArray()
        .filter((node) => node.id() !== focus.id() && !assignments.has(node.id()))
        .sort(layoutSort)
        .forEach((seed) => {
            if (assignments.has(seed.id())) {
                return;
            }

            const group = {
                id: seed.id(),
                root: seed,
                nodes: [],
                nodeIds: new Set(),
            };
            const componentQueue = [seed];
            assignments.set(seed.id(), group.id);
            addNodeToLayoutGroup(group, seed);

            while (componentQueue.length) {
                const node = componentQueue.shift();

                visibleNeighborNodes(node, visibleIds)
                    .sort(layoutSort)
                    .forEach((neighbor) => {
                        if (neighbor.id() === focus.id() || assignments.has(neighbor.id())) {
                            return;
                        }

                        assignments.set(neighbor.id(), group.id);
                        addNodeToLayoutGroup(group, neighbor);
                        componentQueue.push(neighbor);
                    });
            }

            group.nodes.sort((a, b) => (a.id() === group.root.id() ? -1 : 0) - (b.id() === group.root.id() ? -1 : 0) || layoutSort(a, b));
            groups.set(group.id, group);
        });

    const adjacency = new Map();

    cy.edges()
        .not('.network-filtered')
        .forEach((edge) => {
            const sourceGroup = assignments.get(edge.source().id());
            const targetGroup = assignments.get(edge.target().id());

            if (!sourceGroup || !targetGroup || sourceGroup === targetGroup) {
                return;
            }

            const key = groupAdjacencyKey(sourceGroup, targetGroup);
            adjacency.set(key, (adjacency.get(key) || 0) + 1);
        });

    groups.forEach((group) => {
        group.nodes.sort((a, b) => (a.id() === group.root.id() ? -1 : 0) - (b.id() === group.root.id() ? -1 : 0) || layoutSort(a, b));
    });

    return orderGroupsByAdjacency(Array.from(groups.values()), adjacency);
}

function radialDistancesWithinGroup(root, groupNodes) {
    const ids = new Set(groupNodes.map((node) => node.id()));
    const distances = new Map([[root.id(), 0]]);
    const queue = [root];

    while (queue.length) {
        const node = queue.shift();
        const distance = distances.get(node.id()) || 0;

        visibleNeighborNodes(node, ids).forEach((neighbor) => {
            if (distances.has(neighbor.id())) {
                return;
            }

            distances.set(neighbor.id(), distance + 1);
            queue.push(neighbor);
        });
    }

    return distances;
}

function localRingPositions(root, buckets, options = {}) {
    const updates = [{ node: root, position: { x: 0, y: 0 } }];
    const spacingScale = normalizedScale(options.spacingScale, 1, 0.5, 5);
    const baseRadius = (options.baseRadius || 180) * spacingScale;
    const ringGap = (options.ringGap || 160) * spacingScale;

    buckets
        .filter((bucket) => bucket.length)
        .forEach((bucket, ringIndex) => {
            const sorted = [...bucket].sort(layoutSort);
            const radius = baseRadius
                + (ringIndex * ringGap)
                + Math.max(0, sorted.length - 8) * 18 * spacingScale;

            sorted.forEach((node, index) => {
                const angle = (-Math.PI / 2) + ((Math.PI * 2) * (index / Math.max(1, sorted.length))) + ((ringIndex % 2) * 0.18);

                updates.push({
                    node,
                    position: {
                        x: Math.cos(angle) * radius,
                        y: Math.sin(angle) * radius,
                    },
                });
            });
        });

    return updates;
}

function squareShellOffsets(count, slotX = 170, slotY = 154, spacingScale = 1) {
    const offsets = [{ x: 0, y: 0 }];
    const scaledSlotX = slotX * normalizedScale(spacingScale, 1, 0.5, 5);
    const scaledSlotY = slotY * normalizedScale(spacingScale, 1, 0.5, 5);

    for (let ring = 1; offsets.length < count; ring += 1) {
        const candidates = [];

        for (let row = -ring; row <= ring; row += 1) {
            for (let column = -ring; column <= ring; column += 1) {
                if (Math.max(Math.abs(row), Math.abs(column)) !== ring) {
                    continue;
                }

                candidates.push({
                    x: column * scaledSlotX,
                    y: row * scaledSlotY,
                });
            }
        }

        candidates
            .sort((a, b) => Math.hypot(a.x, a.y) - Math.hypot(b.x, b.y) || a.y - b.y || a.x - b.x)
            .forEach((offset) => {
                if (offsets.length < count) {
                    offsets.push(offset);
                }
            });
    }

    return offsets;
}

function localGroupUpdates(cy, group, mode, spacingScale = 1) {
    const root = group.root;
    const spacing = normalizedScale(spacingScale, 1, 0.5, 5);
    const nodes = [root, ...group.nodes.filter((node) => node.id() !== root.id()).sort(layoutSort)];

    if (nodes.length === 1) {
        return [{ node: root, position: { x: 0, y: 0 } }];
    }

    if (mode === 'spiral') {
        const updates = [{ node: root, position: { x: 0, y: 0 } }];
        const goldenAngle = Math.PI * (3 - Math.sqrt(5));

        nodes.slice(1).forEach((node, index) => {
            const step = index + 1;
            const radius = (172 + (58 * Math.sqrt(step))) * spacing;
            const angle = step * goldenAngle;

            updates.push({
                node,
                position: {
                    x: Math.cos(angle) * radius,
                    y: Math.sin(angle) * radius,
                },
            });
        });

        return updates;
    }

    if (mode === 'grid') {
        const offsets = squareShellOffsets(nodes.length, 170, 154, spacing);

        return nodes.map((node, index) => ({
            node,
            position: offsets[index],
        }));
    }

    if (mode === 'concentric') {
        const people = [];
        const strongProfiles = [];
        const profiles = [];
        const candidates = [];

        nodes.slice(1).forEach((node) => {
            if (node.data('type') === 'person') {
                people.push(node);
            } else if (visibleDegree(node) >= 3 || visibilityValue(node.data()) === 'public') {
                strongProfiles.push(node);
            } else if (node.data('type') === 'candidate') {
                candidates.push(node);
            } else {
                profiles.push(node);
            }
        });

        return localRingPositions(root, [people, strongProfiles, profiles, candidates], { baseRadius: 176, ringGap: 155, spacingScale: spacing });
    }

    const distances = radialDistancesWithinGroup(root, nodes);
    const buckets = new Map();
    const unconnected = [];

    nodes.slice(1).forEach((node) => {
        const distance = distances.get(node.id());

        if (!Number.isFinite(distance)) {
            unconnected.push(node);
            return;
        }

        const key = mode === 'radial' ? Math.min(4, Math.max(1, distance)) : Math.min(3, Math.max(1, distance));
        const bucket = buckets.get(key) || [];
        bucket.push(node);
        buckets.set(key, bucket);
    });

    return localRingPositions(root, [
        ...(Array.from(buckets.keys()).sort((a, b) => a - b).map((key) => buckets.get(key))),
        unconnected,
    ], {
        baseRadius: mode === 'radial' ? 178 : 168,
        ringGap: mode === 'radial' ? 158 : 148,
        spacingScale: spacing,
    });
}

function groupDirectlyConnectedToFocus(group, focus) {
    return group.root.connectedEdges()
        .not('.network-filtered')
        .some((edge) => edge.source().id() === focus.id() || edge.target().id() === focus.id());
}

function appendPreparedGroup(updates, item, center) {
    item.localUpdates.forEach((update) => {
        updates.push({
            node: update.node,
            position: {
                x: center.x + update.position.x,
                y: center.y + update.position.y,
            },
        });
    });
}

function placeClusterGroups(preparedGroups, updates, centerX, centerY, spacingScale) {
    let index = 0;
    let ringRadius = 440 * spacingScale;
    let ringIndex = 0;

    while (index < preparedGroups.length) {
        const ring = [];
        let usedArc = 0;
        let maxRadius = 170;
        const circumference = Math.PI * 2 * ringRadius;

        while (index < preparedGroups.length) {
            const item = preparedGroups[index];
            const arc = Math.max(320 * spacingScale, (item.radius * 1.55) + (140 * spacingScale));

            if (ring.length && usedArc + arc > circumference) {
                break;
            }

            ring.push(item);
            usedArc += arc;
            maxRadius = Math.max(maxRadius, item.radius);
            index += 1;
        }

        const angleOffset = (-Math.PI / 2) + (ringIndex * 0.31);

        ring.forEach((item, ringItemIndex) => {
            const angle = angleOffset + ((Math.PI * 2) * (ringItemIndex / Math.max(1, ring.length)));

            appendPreparedGroup(updates, item, {
                x: centerX + Math.cos(angle) * (ringRadius + item.radius * 0.18),
                y: centerY + Math.sin(angle) * (ringRadius + item.radius * 0.18),
            });
        });

        ringRadius += Math.max(460 * spacingScale, (maxRadius * 2.2) + (300 * spacingScale));
        ringIndex += 1;
    }
}

function placeSpiralGroups(preparedGroups, updates, centerX, centerY, spacingScale) {
    const goldenAngle = Math.PI * (3 - Math.sqrt(5));
    let accumulatedRadius = 360 * spacingScale;

    preparedGroups.forEach((item, index) => {
        if (index > 0) {
            accumulatedRadius += Math.max(150 * spacingScale, item.radius * 0.42);
        }

        const angle = (index + 1) * goldenAngle;
        const radius = accumulatedRadius + (Math.sqrt(index + 1) * 190 * spacingScale);

        appendPreparedGroup(updates, item, {
            x: centerX + Math.cos(angle) * radius,
            y: centerY + Math.sin(angle) * radius,
        });
    });
}

function placeGridGroups(preparedGroups, updates, centerX, centerY, spacingScale) {
    const maxRadius = Math.max(180, ...preparedGroups.map((item) => item.radius));
    const columns = Math.max(1, Math.ceil(Math.sqrt(preparedGroups.length)));
    const rows = Math.max(1, Math.ceil(preparedGroups.length / columns));
    const slotX = Math.max(360 * spacingScale, (maxRadius * 2.2) + (180 * spacingScale));
    const slotY = Math.max(320 * spacingScale, (maxRadius * 1.9) + (180 * spacingScale));
    const startX = centerX - (((columns - 1) * slotX) / 2);
    const startY = centerY + (520 * spacingScale) - (((rows - 1) * slotY) / 2);

    preparedGroups.forEach((item, index) => {
        const row = Math.floor(index / columns);
        const column = index % columns;

        appendPreparedGroup(updates, item, {
            x: startX + (column * slotX),
            y: startY + (row * slotY),
        });
    });
}

function placeRadialGroups(preparedGroups, updates, centerX, centerY, spacingScale, focus) {
    const directGroups = preparedGroups.filter((item) => groupDirectlyConnectedToFocus(item.group, focus));
    const indirectGroups = preparedGroups.filter((item) => !groupDirectlyConnectedToFocus(item.group, focus));
    const rings = [directGroups, indirectGroups].filter((ring) => ring.length);

    rings.forEach((ring, ringIndex) => {
        const maxRadius = Math.max(170, ...ring.map((item) => item.radius));
        const radius = (420 * spacingScale)
            + (ringIndex * Math.max(520 * spacingScale, (maxRadius * 2.2) + (260 * spacingScale)));

        ring.forEach((item, index) => {
            const angle = (-Math.PI / 2) + ((Math.PI * 2) * (index / Math.max(1, ring.length))) + (ringIndex * 0.18);

            appendPreparedGroup(updates, item, {
                x: centerX + Math.cos(angle) * (radius + item.radius * 0.16),
                y: centerY + Math.sin(angle) * (radius + item.radius * 0.16),
            });
        });
    });
}

function placeConcentricGroups(preparedGroups, updates, centerX, centerY, spacingScale, focus) {
    const strongGroups = [];
    const mediumGroups = [];
    const weakGroups = [];

    preparedGroups.forEach((item) => {
        const score = groupScore(item.group);

        if (groupDirectlyConnectedToFocus(item.group, focus) && score >= 22) {
            strongGroups.push(item);
        } else if (score >= 12) {
            mediumGroups.push(item);
        } else {
            weakGroups.push(item);
        }
    });

    [strongGroups, mediumGroups, weakGroups]
        .filter((ring) => ring.length)
        .forEach((ring, ringIndex) => {
            const maxRadius = Math.max(170, ...ring.map((item) => item.radius));
            const radius = (360 * spacingScale)
                + (ringIndex * Math.max(470 * spacingScale, (maxRadius * 2) + (250 * spacingScale)));

            ring.forEach((item, index) => {
                const angle = (-Math.PI / 2) + ((Math.PI * 2) * (index / Math.max(1, ring.length))) + (ringIndex * 0.36);

                appendPreparedGroup(updates, item, {
                    x: centerX + Math.cos(angle) * (radius + item.radius * 0.12),
                    y: centerY + Math.sin(angle) * (radius + item.radius * 0.12),
                });
            });
        });
}

function placeGroupedLayout(root, cy, visibleNodes, mode) {
    const state = instances.get(root);
    const spacingScale = normalizedScale(state?.layoutSpacingScale, 1, 0.5, 5);
    const nodes = visibleNodes.toArray();
    const focus = primaryLayoutNode(nodes);
    const { centerX, centerY } = layoutCenter(
        visibleNodes,
        Math.max(1300, visibleNodes.length * 38),
        Math.max(900, visibleNodes.length * 28),
    );
    const groups = connectedLayoutGroups(cy, visibleNodes, focus);
    const updates = [{ node: focus, position: { x: centerX, y: centerY } }];

    if (!groups.length) {
        return updates;
    }

    const preparedGroups = groups.map((group) => {
        const localUpdates = localGroupUpdates(cy, group, mode, spacingScale);
        const bounds = updatesBounds(localUpdates);

        return {
            group,
            localUpdates,
            bounds,
            radius: Math.max(170, bounds.radius),
        };
    });

    if (mode === 'spiral') {
        placeSpiralGroups(preparedGroups, updates, centerX, centerY, spacingScale);
    } else if (mode === 'grid') {
        placeGridGroups(preparedGroups, updates, centerX, centerY, spacingScale);
    } else if (mode === 'radial') {
        placeRadialGroups(preparedGroups, updates, centerX, centerY, spacingScale, focus);
    } else if (mode === 'concentric') {
        placeConcentricGroups(preparedGroups, updates, centerX, centerY, spacingScale, focus);
    } else {
        placeClusterGroups(preparedGroups, updates, centerX, centerY, spacingScale);
    }

    return useAvailableLayoutSpace(
        cy,
        preventLayoutOverlaps(updates, focus, spacingScale),
        focus,
    );
}

function scaledLayoutUpdates(updates, focus, scaleX, scaleY = scaleX) {
    const origin = updates.find((update) => update.node.id() === focus.id())?.position
        || updatesBounds(updates);
    const centerX = Number(origin.x ?? ((origin.left + origin.right) / 2)) || 0;
    const centerY = Number(origin.y ?? ((origin.top + origin.bottom) / 2)) || 0;

    return updates.map((update) => ({
        node: update.node,
        position: {
            x: centerX + ((update.position.x - centerX) * scaleX),
            y: centerY + ((update.position.y - centerY) * scaleY),
        },
    }));
}

function layoutHasOverlaps(updates) {
    for (let leftIndex = 0; leftIndex < updates.length; leftIndex += 1) {
        const left = updates[leftIndex];
        const leftBox = estimatedNodeBox(left.node, left.position);

        for (let rightIndex = leftIndex + 1; rightIndex < updates.length; rightIndex += 1) {
            const right = updates[rightIndex];

            if (boxesOverlap(leftBox, estimatedNodeBox(right.node, right.position))) {
                return true;
            }
        }
    }

    return false;
}

function compactLayoutUpdates(updates, focus) {
    if (updates.length < 2 || updates.length > 900) {
        return updates;
    }

    let lower = 0.2;
    let upper = 1;

    for (let pass = 0; pass < 12; pass += 1) {
        const scale = (lower + upper) / 2;
        const candidate = scaledLayoutUpdates(updates, focus, scale);

        if (layoutHasOverlaps(candidate)) {
            lower = scale;
        } else {
            upper = scale;
        }
    }

    return scaledLayoutUpdates(updates, focus, Math.min(1, upper * 1.025));
}

function useAvailableLayoutSpace(cy, updates, focus) {
    const compacted = compactLayoutUpdates(updates, focus);
    const bounds = updatesBounds(compacted);
    const viewportWidth = Math.max(1, cy.width());
    const viewportHeight = Math.max(1, cy.height());
    const viewportRatio = viewportWidth / viewportHeight;
    const layoutRatio = Math.max(0.01, bounds.width / Math.max(1, bounds.height));

    if (layoutRatio > viewportRatio) {
        return scaledLayoutUpdates(compacted, focus, 1, Math.min(2.5, layoutRatio / viewportRatio));
    }

    return scaledLayoutUpdates(compacted, focus, Math.min(2.5, viewportRatio / layoutRatio), 1);
}

function preventLayoutOverlaps(updates, focus, spacingScale = 1) {
    if (updates.length > 900) {
        return updates;
    }

    const items = updates.map((update, index) => ({
        ...update,
        index,
        fixed: update.node.id() === focus.id(),
        position: { ...update.position },
    }));

    const focusItem = items.find((item) => item.fixed);

    for (let pass = 0; pass < 18; pass += 1) {
        let moved = false;

        for (let leftIndex = 0; leftIndex < items.length; leftIndex += 1) {
            for (let rightIndex = leftIndex + 1; rightIndex < items.length; rightIndex += 1) {
                const left = items[leftIndex];
                const right = items[rightIndex];
                const leftBox = estimatedNodeBox(left.node, left.position);
                const rightBox = estimatedNodeBox(right.node, right.position);

                if (!boxesOverlap(leftBox, rightBox)) {
                    continue;
                }

                const overlap = overlapAmount(leftBox, rightBox);
                let dx = right.position.x - left.position.x;
                let dy = right.position.y - left.position.y;

                if (Math.abs(dx) < 0.1 && Math.abs(dy) < 0.1) {
                    const angle = ((left.index + right.index + 1) * Math.PI) / 5;
                    dx = Math.cos(angle);
                    dy = Math.sin(angle);
                }

                const length = Math.max(1, Math.hypot(dx, dy));
                const push = Math.min(280 * spacingScale, Math.max(overlap.x, overlap.y) * 0.72 + (24 * spacingScale));
                const pushX = (dx / length) * push;
                const pushY = (dy / length) * push;

                if (left.fixed && right.fixed) {
                    continue;
                }

                if (left.fixed) {
                    right.position.x += pushX;
                    right.position.y += pushY;
                } else if (right.fixed) {
                    left.position.x -= pushX;
                    left.position.y -= pushY;
                } else {
                    left.position.x -= pushX / 2;
                    left.position.y -= pushY / 2;
                    right.position.x += pushX / 2;
                    right.position.y += pushY / 2;
                }

                moved = true;
            }
        }

        if (!moved) {
            break;
        }

        if (focusItem && pass % 3 === 2) {
            items.forEach((item) => {
                if (item.fixed) {
                    return;
                }

                const dx = item.position.x - focusItem.position.x;
                const dy = item.position.y - focusItem.position.y;
                const length = Math.max(1, Math.hypot(dx, dy));
                const nudge = 8 * spacingScale;

                item.position.x += (dx / length) * nudge;
                item.position.y += (dy / length) * nudge;
            });
        }
    }

    return items.map((item) => ({
        node: item.node,
        position: item.position,
    }));
}

function clusterLayoutUpdates(cy, visibleNodes) {
    const { centerX, centerY } = layoutCenter(visibleNodes);
    const nodes = visibleNodes.toArray();
    const primary = primaryLayoutNode(nodes);
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

    anchors.sort(layoutSort);

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
        .sort(layoutSort)
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

    return updates;
}

function spiralLayoutUpdates(visibleNodes) {
    const { centerX, centerY } = layoutCenter(visibleNodes, 1000, 760);
    const nodes = visibleNodes.toArray();
    const primary = primaryLayoutNode(nodes);
    const sortedNodes = nodes.filter((node) => node.id() !== primary.id()).sort(layoutSort);
    const updates = [{ node: primary, position: { x: centerX, y: centerY } }];
    const goldenAngle = Math.PI * (3 - Math.sqrt(5));

    sortedNodes.forEach((node, index) => {
        const step = index + 1;
        const radius = 92 + (46 * Math.sqrt(step));
        const angle = step * goldenAngle;

        updates.push({
            node,
            position: {
                x: centerX + Math.cos(angle) * radius,
                y: centerY + Math.sin(angle) * radius,
            },
        });
    });

    return updates;
}

function radialDistances(cy, primary) {
    const distances = new Map([[primary.id(), 0]]);
    const queue = [primary];

    while (queue.length) {
        const node = queue.shift();
        const distance = distances.get(node.id()) || 0;

        node.connectedEdges()
            .not('.network-filtered')
            .connectedNodes()
            .forEach((connectedNode) => {
                if (distances.has(connectedNode.id())) {
                    return;
                }

                distances.set(connectedNode.id(), distance + 1);
                queue.push(connectedNode);
            });
    }

    return distances;
}

function ringLayoutUpdates(visibleNodes, groups, options = {}) {
    const { centerX, centerY } = layoutCenter(visibleNodes, options.minWidth || 1100, options.minHeight || 780);
    const updates = [];
    const firstGroup = groups.shift() || [];
    const centerNode = firstGroup[0] || visibleNodes.first();

    if (centerNode && centerNode.length !== 0) {
        updates.push({ node: centerNode, position: { x: centerX, y: centerY } });
    }

    groups
        .filter((group) => group.length)
        .forEach((group, ringIndex) => {
            const radius = (options.baseRadius || 220)
                + (ringIndex * (options.ringGap || 175))
                + Math.min(190, Math.max(0, group.length - 18) * 4);

            group.sort(layoutSort).forEach((node, index) => {
                const angle = (-Math.PI / 2) + ((Math.PI * 2) * (index / Math.max(1, group.length)));

                updates.push({
                    node,
                    position: {
                        x: centerX + Math.cos(angle) * radius,
                        y: centerY + Math.sin(angle) * radius,
                    },
                });
            });
        });

    return updates;
}

function radialLayoutUpdates(cy, visibleNodes) {
    const nodes = visibleNodes.toArray();
    const primary = primaryLayoutNode(nodes);
    const distances = radialDistances(cy, primary);
    const rings = new Map();
    const unconnected = [];

    nodes.forEach((node) => {
        if (node.id() === primary.id()) {
            return;
        }

        const distance = distances.get(node.id());

        if (!Number.isFinite(distance)) {
            unconnected.push(node);
            return;
        }

        const key = Math.min(4, Math.max(1, distance));
        const group = rings.get(key) || [];
        group.push(node);
        rings.set(key, group);
    });

    return ringLayoutUpdates(visibleNodes, [
        [primary],
        ...(Array.from(rings.keys()).sort((a, b) => a - b).map((key) => rings.get(key))),
        unconnected,
    ], { baseRadius: 210, ringGap: 175 });
}

function concentricLayoutUpdates(visibleNodes) {
    const nodes = visibleNodes.toArray();
    const primary = primaryLayoutNode(nodes);
    const people = [];
    const strongProfiles = [];
    const profiles = [];
    const candidates = [];

    nodes.forEach((node) => {
        if (node.id() === primary.id()) {
            return;
        }

        if (node.data('type') === 'person') {
            people.push(node);
        } else if (visibleDegree(node) >= 3 || visibilityValue(node.data()) === 'public') {
            strongProfiles.push(node);
        } else if (node.data('type') === 'candidate') {
            candidates.push(node);
        } else {
            profiles.push(node);
        }
    });

    return ringLayoutUpdates(visibleNodes, [
        [primary],
        people,
        strongProfiles,
        profiles,
        candidates,
    ], { baseRadius: 205, ringGap: 165 });
}

function gridLayoutUpdates(visibleNodes) {
    const nodes = visibleNodes.toArray().sort(layoutSort);
    const primary = primaryLayoutNode(nodes);
    const sortedNodes = [primary, ...nodes.filter((node) => node.id() !== primary.id())];
    const columns = Math.max(1, Math.ceil(Math.sqrt(sortedNodes.length * 1.35)));
    const spacingX = 104;
    const spacingY = 104;
    const width = Math.max(820, columns * spacingX);
    const rows = Math.max(1, Math.ceil(sortedNodes.length / columns));
    const height = Math.max(680, rows * spacingY);
    const startX = (width / 2) - (((columns - 1) * spacingX) / 2);
    const startY = (height / 2) - (((rows - 1) * spacingY) / 2);

    return sortedNodes.map((node, index) => {
        const row = Math.floor(index / columns);
        const column = index % columns;

        return {
            node,
            position: {
                x: startX + (column * spacingX),
                y: startY + (row * spacingY),
            },
        };
    });
}

function layoutUpdatesForMode(root, cy, visibleNodes, mode) {
    return placeGroupedLayout(root, cy, visibleNodes, normalizeLayoutMode(mode));
}

function arrangeVisibleGraph(root, cy, animate = true, mode = null, options = {}) {
    const state = instances.get(root);
    const visibleNodes = cy.nodes().not('.network-filtered');

    if (!visibleNodes.length) {
        return;
    }

    const layoutMode = normalizeLayoutMode(mode || state?.layoutMode);
    const updates = layoutUpdatesForMode(root, cy, visibleNodes, layoutMode);
    const shouldAnimate = animate && updates.length <= 650;

    if (state) {
        state.layoutMode = layoutMode;
        state.hasAppliedLayout = true;
        state.layoutRestored = false;
        state.suppressLayoutSave = true;
        updateLayoutControls(root, state);
        writeStoredLayoutMode(root, layoutMode);
        setLayoutStatus(root, 'Layout wird berechnet');
    }

    if (shouldAnimate) {
        updates.forEach(({ node, position }) => {
            node.animate({ position }, { duration: 320 });
        });
    } else {
        cy.batch(() => {
            updates.forEach(({ node, position }) => node.position(position));
        });
    }

    window.setTimeout(() => {
        if (state) {
            state.suppressLayoutSave = false;
            state.layoutRestored = true;
        }

        if (options.fit !== false) {
            fitGraph(cy, { tight: true });
        } else {
            schedulePublicBadgeUpdate(root, cy);
        }
        scheduleStoredLayoutSave(root, cy, 120);
        scheduleThreeRender(root);
    }, shouldAnimate ? 360 : 30);
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

        if (node.length && state.viewMode === '3d') {
            setThreeFocusNode(root, state, node.id());
        }

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

function applyFilters(root, cy, options = {}) {
    const state = instances.get(root);

    if (!state) {
        return;
    }

    cy.elements().removeClass('network-filtered');

    cy.edges().forEach((edge) => {
        if (!edgeVisibleForState(edge, state)) {
            edge.addClass('network-filtered');
        }
    });
    applyEdgeRenderState(cy, state);

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

    applyVisualSettings(root, cy);

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

    if (options.layout === true) {
        arrangeVisibleGraph(root, cy, options.animate !== false, state.layoutMode);
    }

    updateSelectionPanel(root, cy);
    schedulePublicBadgeUpdate(root, cy);
    scheduleThreeRender(root);
}

function fitGraph(cy, options = {}) {
    if (rootStateForCy(cy)?.state?.viewMode === '3d') {
        return;
    }

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
    scheduleStoredLayoutSaveFromCy(cy, 220);
}

function resolveVisualOverlaps(root, cy, animate = true) {
    const visibleNodes = cy.nodes().not('.network-filtered');

    if (!visibleNodes.length) {
        return;
    }

    const nodes = visibleNodes.toArray();
    const focus = primaryLayoutNode(nodes);
    const updates = preventLayoutOverlaps(
        nodes.map((node) => ({ node, position: { ...node.position() } })),
        focus,
        normalizedScale(instances.get(root)?.layoutSpacingScale, 1, 0.5, 5),
    );
    const shouldAnimate = animate && updates.length <= 650;

    if (shouldAnimate) {
        updates.forEach(({ node, position }) => {
            node.animate({ position }, { duration: 220 });
        });
    } else {
        cy.batch(() => {
            updates.forEach(({ node, position }) => node.position(position));
        });
    }

    window.setTimeout(() => {
        schedulePublicBadgeUpdate(root, cy);
        scheduleStoredLayoutSave(root, cy, 120);
        scheduleThreeRender(root);
    }, shouldAnimate ? 250 : 30);
}

function persistLayoutSettings(root, state) {
    clearStoredLayout(root, state.cy);
    writeStoredFilters(root, state);
    writeStoredLayoutSettings(root, state);
    updateLayoutControls(root, state);
}

function applySpacingScale(root, cy, previousScale) {
    const state = instances.get(root);

    if (!state) {
        return;
    }

    persistLayoutSettings(root, state);
    const visibleNodes = cy.nodes().not('.network-filtered');

    if (!visibleNodes.length) {
        return;
    }

    const nodes = visibleNodes.toArray();
    const focus = primaryLayoutNode(nodes);
    const ratio = normalizedScale(state.layoutSpacingScale, 1, 0.5, 5)
        / normalizedScale(previousScale, 1, 0.5, 5);
    const scaled = scaledLayoutUpdates(
        nodes.map((node) => ({ node, position: { ...node.position() } })),
        focus,
        ratio,
    );
    const updates = ratio < 1
        ? preventLayoutOverlaps(scaled, focus, state.layoutSpacingScale)
        : scaled;

    cy.batch(() => {
        updates.forEach(({ node, position }) => node.position(position));
    });
    schedulePublicBadgeUpdate(root, cy);
    scheduleStoredLayoutSave(root, cy, 120);
    scheduleThreeRender(root);
}

function scheduleVisualSettingsRefresh(root, cy) {
    const state = instances.get(root);

    if (!state) {
        return;
    }

    persistLayoutSettings(root, state);
    applyVisualSettings(root, cy);
    window.clearTimeout(state.layoutSettingsTimer);
    state.layoutSettingsTimer = window.setTimeout(() => {
        resolveVisualOverlaps(root, cy, true);
    }, 120);
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
            if (state.viewMode === '3d') {
                scheduleThreeRender(root);
            } else {
                fitGraph(cy, { tight: true });
            }
        });
    });

    root.querySelector('[data-network-filter-min-degree]')?.addEventListener('change', (event) => {
        state.minDegree = Math.max(0, Number(event.target.value) || 0);
        state.hasStoredMinDegree = true;
        applyFilters(root, cy);
        if (state.viewMode === '3d') {
            scheduleThreeRender(root);
        } else {
            fitGraph(cy, { tight: true });
        }
    });

    root.querySelector('[data-network-filter-max-profiles]')?.addEventListener('change', (event) => {
        state.maxVisibleProfiles = Math.max(0, Number(event.target.value) || 0);
        applyFilters(root, cy);
        if (state.viewMode === '3d') {
            scheduleThreeRender(root);
        } else {
            fitGraph(cy, { tight: true });
        }
    });

    root.querySelectorAll('[data-network-layout-mode]').forEach((control) => {
        control.addEventListener('change', (event) => {
            state.layoutMode = normalizeLayoutMode(event.target.value);
            writeStoredLayoutMode(root, state.layoutMode);
            clearStoredLayout(root, cy);
            arrangeVisibleGraph(root, cy, true, state.layoutMode, { fit: state.viewMode !== '3d' });
            scheduleThreeRender(root);
        });
    });

    root.querySelectorAll('[data-network-layout-reset]').forEach((button) => {
        button.addEventListener('click', () => {
            clearStoredLayout(root, cy);
            arrangeVisibleGraph(root, cy, true, state.layoutMode, { fit: state.viewMode !== '3d' });
            scheduleThreeRender(root);
        });
    });

    root.querySelectorAll('[data-network-view-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            setViewMode(root, state, button.dataset.networkViewMode);
        });
    });

    root.querySelectorAll('[data-network-layout-spacing]').forEach((control) => {
        control.addEventListener('input', () => {
            const previousScale = state.layoutSpacingScale;
            state.layoutSpacingScale = controlScaleValue(control, 1, 0.5, 5);
            applySpacingScale(root, cy, previousScale);
        });
    });

    root.querySelectorAll('[data-network-icon-scale]').forEach((control) => {
        control.addEventListener('input', () => {
            state.nodeSizeScale = controlScaleValue(control, 1, 0.5, 5);
            scheduleVisualSettingsRefresh(root, cy);
        });
    });

    root.querySelectorAll('[data-network-size-variance]').forEach((control) => {
        control.addEventListener('input', () => {
            state.nodeSizeVariance = controlScaleValue(control, 1, 0, 4);
            scheduleVisualSettingsRefresh(root, cy);
        });
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

function bindLayoutPersistence(root, cy) {
    const state = instances.get(root);

    cy.on('dragfree', 'node', () => {
        if (state) {
            state.layoutRestored = true;
        }

        scheduleStoredLayoutSave(root, cy, 120);
    });

    cy.on('pan zoom', () => {
        scheduleStoredLayoutSave(root, cy, 520);
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
                    width: 'data(renderNodeSize)',
                    height: 'data(renderNodeSize)',
                    'background-color': '#eff6ff',
                    'border-color': '#94a3b8',
                    'border-width': 2,
                    color: '#0f172a',
                    label: 'data(label)',
                    'font-size': 'data(renderNodeFontSize)',
                    'font-weight': 700,
                    'text-background-color': '#ffffff',
                    'text-background-opacity': 0.88,
                    'text-background-padding': 4,
                    'text-border-color': '#e2e8f0',
                    'text-border-width': 1,
                    'text-border-opacity': 0.9,
                    'text-margin-y': 10,
                    'text-max-width': 'data(renderTextMaxWidth)',
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
                    'border-color': '#22c55e',
                    'border-width': 4,
                },
            },
            {
                selector: '.network-profile-private',
                style: {
                    'border-color': '#64748b',
                    'border-width': 4,
                },
            },
            {
                selector: '.network-profile-unknown',
                style: {
                    'border-color': '#64748b',
                    'border-width': 4,
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
                selector: '.network-profile-muted-image',
                style: {
                    'background-color': '#e2e8f0',
                    'background-opacity': 0,
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
                    width: 'data(edgeWidth)',
                    'line-color': 'data(lineColor)',
                    'source-arrow-color': 'data(sourceArrowColor)',
                    'target-arrow-color': 'data(targetArrowColor)',
                    'source-arrow-shape': 'data(sourceArrowShape)',
                    'target-arrow-shape': 'data(targetArrowShape)',
                    'arrow-scale': 0.78,
                    'curve-style': 'bezier',
                    'line-style': 'data(lineStyle)',
                    opacity: 'data(edgeOpacity)',
                },
            },
            {
                selector: 'edge[networkType = "inferred"]',
                style: {
                    width: 'data(edgeWidth)',
                },
            },
            {
                selector: 'edge[networkType = "tracked-list"]',
                style: {
                    width: 'data(edgeWidth)',
                },
            },
            {
                selector: 'edge[networkType = "tracked-profile-rel"]',
                style: {
                    width: 'data(edgeWidth)',
                },
            },
            {
                selector: 'edge[otherUserEvidence]',
                style: {
                    width: 1.55,
                },
            },
            {
                selector: 'edge[mutualFollowing = true]',
                style: {
                    width: 'data(edgeWidth)',
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
        const storedLayoutSettings = readStoredLayoutSettings(root);
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
            layoutSpacingScale: normalizedScale(storedFilters.layoutSpacingScale ?? storedLayoutSettings.layoutSpacingScale, 1, 0.5, 5),
            nodeSizeScale: normalizedScale(storedFilters.nodeSizeScale ?? storedLayoutSettings.nodeSizeScale, 1, 0.5, 5),
            nodeSizeVariance: normalizedScale(storedFilters.nodeSizeVariance ?? storedLayoutSettings.nodeSizeVariance, 1, 0, 4),
            hasStoredMinDegree: storedMinDegree !== null,
            autoMinDegreeApplied: false,
            selectedId: null,
            resizeHandler,
            loadGeneration: 0,
            loadedNodes: initialElements.filter((element) => element.group === 'nodes').length,
            loadedEdges: initialElements.filter((element) => element.group === 'edges').length,
            lastNodeTap: null,
            nodeTapTimer: null,
            graphToken: String(root.dataset.networkGraphToken || '').trim(),
            graphDataHash: String(root.dataset.networkGraphHash || '').trim(),
            layoutMode: readStoredLayoutMode(root),
            viewMode: readStoredViewMode(root),
            threeFocusNodeId: null,
            layoutRestored: false,
            hasAppliedLayout: false,
            isLoadingGraph: false,
            suppressLayoutSave: false,
            layoutSaveTimer: null,
            layoutSettingsTimer: null,
            threeState: null,
            threeRenderQueued: false,
        };

        instances.set(root, state);
        delete root.dataset.networkMapInitializing;
        activeRoots.add(root);

        bindControls(root, cy);
        bindPublicBadges(root, cy);
        bindLayoutPersistence(root, cy);
        updateLayoutControls(root, state);
        updateViewModeControls(root, state);
        setLayoutStatus(root, 'Noch nicht gespeichert');
        applyVisualSettings(root, cy);
        applyAutoMinDegreeIfNeeded(root, cy);
        applyFilters(root, cy);
        setViewMode(root, state, state.viewMode);

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
            window.setTimeout(() => {
                if (!applyStoredLayout(root, cy)) {
                    arrangeVisibleGraph(root, cy, false, state.layoutMode);
                }
            }, 150);
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

function mergeEdgeData(existingEdge, incomingData) {
    const source = existingEdge.data('source');
    const target = existingEdge.data('target');
    const evidences = uniqueEdgeEvidences([
        ...edgeEvidences(existingEdge),
        ...(Array.isArray(incomingData.edgeEvidences) ? incomingData.edgeEvidences : edgeEvidences({ data: () => incomingData })),
    ]);

    existingEdge.data(combinedEdgeData(source, target, evidences));
}

function addGraphChunk(root, cy, chunk) {
    const nodeElements = toElements({ nodes: chunk.nodes || [], edges: [] })
        .filter((element) => !cy.getElementById(element.data.id).length);

    if (nodeElements.length) {
        cy.add(nodeElements);
    }

    const edgeElements = [];

    toElements({ nodes: [], edges: chunk.edges || [] }).forEach((element) => {
        const sourceExists = cy.getElementById(element.data.source).length > 0;
        const targetExists = cy.getElementById(element.data.target).length > 0;

        if (!sourceExists || !targetExists) {
            return;
        }

        const existingEdge = cy.getElementById(element.data.id);

        if (existingEdge.length) {
            mergeEdgeData(existingEdge, element.data);
            return;
        }

        edgeElements.push(element);
    });

    if (edgeElements.length) {
        cy.add(edgeElements);
    }

    const state = instances.get(root);

    if (state) {
        state.loadedNodes += nodeElements.length;
        state.loadedEdges += edgeElements.length;
        applyEdgeRenderState(cy, state);
        applyVisualSettings(root, cy);
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
    state.isLoadingGraph = false;
    state.layoutRestored = false;
    state.hasAppliedLayout = false;
    window.clearTimeout(state.layoutSaveTimer);
    window.clearTimeout(state.layoutSettingsTimer);
    state.cy.elements().remove();
    updateSelectionPanel(root, state.cy);
    schedulePublicBadgeUpdate(root, state.cy);
    scheduleThreeRender(root);
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
    updateGraphIdentity(root, state, detail);
    state.loadGeneration = generation;
    state.loadedNodes = 0;
    state.loadedEdges = 0;
    state.selectedId = null;
    state.isLoadingGraph = true;
    state.layoutRestored = false;
    state.hasAppliedLayout = false;
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
        applyFilters(root, cy, { layout: false });
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
    applyFilters(root, cy, { layout: false });
    state.isLoadingGraph = false;
    updateBuildStatus(root, {
        visible: true,
        label: 'Netzwerk geladen',
        text: `${state.loadedNodes.toLocaleString('de-DE')} Knoten und ${state.loadedEdges.toLocaleString('de-DE')} Kanten sichtbar.`,
        count: 'Fertig',
        progress: 100,
        state: 'done',
    });

    window.requestAnimationFrame(() => {
        if (!applyStoredLayout(root, cy)) {
            arrangeVisibleGraph(root, cy, false, state.layoutMode);
        } else {
            schedulePublicBadgeUpdate(root, cy);
        }

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
        const state = instances.get(root);

        if (state) {
            state.isLoadingGraph = false;
        }

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
        window.clearTimeout(state.layoutSaveTimer);
        window.clearTimeout(state.layoutSettingsTimer);
        destroyThreeScene(state);
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

    const refreshAndFit = () => {
        if (state.viewMode === '3d') {
            state.threeState?.resize?.();
            scheduleThreeRender(root);

            return;
        }

        state.cy.resize();
        fitGraph(state.cy, { tight: true });
        schedulePublicBadgeUpdate(root, state.cy);
    };

    window.requestAnimationFrame(() => {
        refreshAndFit();
        window.requestAnimationFrame(refreshAndFit);
        window.setTimeout(refreshAndFit, 180);
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
