import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { createCamera, panBy, screenToWorld, zoomAt } from '../lib/camera.js';
import { buildGrid, nearestNode } from '../lib/spatial_grid.js';
import { drawTree } from '../lib/tree_renderer.js';

const DRAG_SLOP = 4;
const CLICK_RADIUS = 30;

export default class extends Controller {
    static targets = ['canvas', 'state', 'tooltip'];
    static values = { treeUrl: String, actUrl: String };

    async connect() {
        this.camera = createCamera();
        this.allocated = new Set();
        this.nodes = new Map();
        this.edges = [];
        this.hovered = null;
        this.startNodeId = null;

        this.readState();
        await this.loadTree();
        this.resize();
        this.redraw();

        this.onResize = () => { this.resize(); this.redraw(); };
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    // Turbo replaces the state tag after every edit, including one made from
    // the node list or a revert. Re-reading here is what keeps the canvas
    // honest without a second channel back from the server.
    stateTargetConnected() {
        this.readState();

        if (this.nodes?.size) {
            this.redraw();
        }
    }

    readState() {
        const state = JSON.parse(this.stateTarget.textContent || '{}');

        this.allocated = new Set(state.allocated ?? []);
        this.ascendancy = state.ascendancy ?? null;
        this.classKey = state.classKey ?? null;
    }

    async loadTree() {
        const response = await fetch(this.treeUrlValue, { headers: { Accept: 'application/json' } });
        const tree = await response.json();

        for (const [id, name, kind, ascendancy, x, y, stats, recipe] of tree.nodes) {
            // Another ascendancy's nodes are not reachable by this build and
            // would only be clutter around the part that is.
            if (ascendancy && ascendancy !== this.ascendancy) {
                continue;
            }

            this.nodes.set(id, { id, name, kind, x, y, stats: stats ?? [], recipe: recipe ?? [] });
        }

        this.edges = tree.edges.filter(([from, to]) => this.nodes.has(from) && this.nodes.has(to));

        const startClass = (tree.classes ?? []).find((c) => c.id === this.classKey);
        this.startNodeId = startClass?.start_node_id ?? null;

        const start = this.startNodeId ? this.nodes.get(this.startNodeId) : null;
        this.camera = createCamera({ scale: 0.12, x: start?.x ?? 0, y: start?.y ?? 0 });
    }

    resize() {
        const canvas = this.canvasTarget;
        const ratio = window.devicePixelRatio || 1;

        canvas.width = canvas.clientWidth * ratio;
        canvas.height = canvas.clientHeight * ratio;
    }

    redraw() {
        const context = this.canvasTarget.getContext('2d');

        drawTree(context, {
            nodes: this.nodes,
            edges: this.edges,
            allocated: this.allocated,
            startNodeId: this.startNodeId,
            hovered: this.hovered,
        }, this.camera);
    }

    pointerdown(event) {
        this.dragging = { x: event.offsetX, y: event.offsetY, moved: 0 };
        this.canvasTarget.setPointerCapture(event.pointerId);
        this.showTooltip(null);
    }

    pointermove(event) {
        if (this.dragging) {
            // event.offsetX/Y are CSS pixels. The drag/click threshold is
            // measured in that same unit — DRAG_SLOP is a physical distance
            // the pointer travelled, and should feel the same regardless of
            // devicePixelRatio. The camera, though, is scaled against
            // canvas.width/height, which are device pixels (see resize()),
            // the same space wheel() and nodeAt() already convert into. So
            // the CSS-pixel delta is only converted to device pixels at the
            // point it is handed to panBy, to agree with zoom and hit-testing.
            const dx = event.offsetX - this.dragging.x;
            const dy = event.offsetY - this.dragging.y;

            this.dragging.moved += Math.abs(dx) + Math.abs(dy);
            this.dragging.x = event.offsetX;
            this.dragging.y = event.offsetY;

            const ratio = this.ratio();
            this.camera = panBy(this.camera, dx * ratio, dy * ratio);
            this.redraw();

            return;
        }

        const node = this.nodeAt(event.offsetX, event.offsetY);
        const id = node?.id ?? null;

        if (id !== this.hovered) {
            this.hovered = id;
            this.redraw();
        }

        this.showTooltip(node, event.offsetX, event.offsetY);
    }

    // Moving off a node without crossing another one still fires pointermove,
    // which hides the tooltip — but moving straight off the canvas edge does
    // not fire another pointermove at all, so without this the last tooltip
    // would stay on screen indefinitely.
    pointerleave() {
        this.showTooltip(null);
    }

    pointerup(event) {
        const dragged = (this.dragging?.moved ?? 0) > DRAG_SLOP;
        this.dragging = null;

        if (dragged) {
            return;
        }

        const node = this.nodeAt(event.offsetX, event.offsetY);

        if (node && node.id !== this.startNodeId) {
            this.toggle(node.id);
        }
    }

    wheel(event) {
        event.preventDefault();
        this.camera = zoomAt(this.camera, this.canvasTarget.width, this.canvasTarget.height, event.offsetX * this.ratio(), event.offsetY * this.ratio(), event.deltaY < 0 ? 1.15 : 1 / 1.15);
        this.redraw();
    }

    nodeAt(offsetX, offsetY) {
        const ratio = this.ratio();
        const world = screenToWorld(this.camera, this.canvasTarget.width, this.canvasTarget.height, offsetX * ratio, offsetY * ratio);

        this.grid ??= buildGrid([...this.nodes.values()]);

        return nearestNode(this.grid, world.x, world.y, CLICK_RADIUS / this.camera.scale);
    }

    ratio() {
        return this.canvasTarget.width / this.canvasTarget.clientWidth;
    }

    showTooltip(node, offsetX, offsetY) {
        const tip = this.tooltipTarget;

        if (!node) {
            tip.hidden = true;

            return;
        }

        const stats = node.stats.map((line) => `<li>${escapeHtml(line)}</li>`).join('');
        const cost = node.recipe.length
            ? `<span class="cost">Instil with ${node.recipe.map(escapeHtml).join(', ')}</span>`
            : '';

        tip.innerHTML = `<h3>${escapeHtml(node.name)}</h3><ul>${stats}</ul>${cost}`;

        // Unhide before measuring: a `hidden` element lays out nowhere, so
        // offsetWidth/offsetHeight below would read 0.
        tip.hidden = false;

        // event.offsetX/Y are relative to the canvas, but the tooltip's
        // containing block is #build-tree's padding edge — the canvas sits
        // canvasTarget.offsetLeft/offsetTop into that box (below the <h2>,
        // and the "choose a class" paragraph when none is picked yet), so
        // that offset has to be folded in before the cursor position means
        // anything in the tooltip's own coordinate space.
        const canvas = this.canvasTarget;
        const container = this.element;
        const left = canvas.offsetLeft + offsetX + 14;
        const top = canvas.offsetTop + offsetY + 14;

        // Clamp rather than flip: flipping needs symmetric edge detection on
        // both axes and still fails once the tooltip is wider than the space
        // on either side. Clamping keeps it inside the section on every edge.
        tip.style.left = `${Math.max(0, Math.min(left, container.clientWidth - tip.offsetWidth))}px`;
        tip.style.top = `${Math.max(0, Math.min(top, container.clientHeight - tip.offsetHeight))}px`;
    }

    async toggle(id) {
        const allocating = !this.allocated.has(id);
        const before = new Set(this.allocated);

        // Draw the change at once and put it back if the server refuses:
        // waiting a round trip before the node lights up makes the tree feel
        // broken on a slow connection. The rollback below restores this exact
        // snapshot rather than inverting the toggle, so it is correct even if
        // something else touched `this.allocated` while the request was in flight.
        if (allocating) {
            this.allocated.add(id);
        } else {
            this.allocated.delete(id);
        }

        this.redraw();

        const body = new URLSearchParams({ action: allocating ? 'passive.allocate' : 'passive.deallocate', id });

        let response;
        try {
            response = await fetch(this.actUrlValue, {
                method: 'POST',
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                body,
            });
        } catch {
            // Request never reached (or never returned from) the network:
            // there is no response body of any kind to render.
            this.allocated = before;
            this.redraw();

            return;
        }

        if (response.ok) {
            Turbo.renderStreamMessage(await response.text());

            return;
        }

        if (422 === response.status) {
            // Rejected, but the server still renders a full turbo-stream for
            // a 422 — an error message plus a replaced build-state tag — so
            // roll back the optimistic guess first and then render it: the
            // render is what resyncs `this.allocated` from server truth via
            // stateTargetConnected(). Do not drop this render "to match the
            // other failure case" — without it the canvas is left holding
            // the rolled-back guess instead of the server's actual state.
            this.allocated = before;
            this.redraw();
            Turbo.renderStreamMessage(await response.text());

            return;
        }

        // Anything else (500, etc.): the body is not guaranteed to be a
        // turbo-stream, so roll back and stop rather than hand an arbitrary
        // error page to Turbo.
        this.allocated = before;
        this.redraw();
    }
}

function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
