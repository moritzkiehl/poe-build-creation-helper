import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { createCamera, panBy, screenToWorld, zoomAt } from '../lib/camera.js';
import { buildGrid, nearestNode } from '../lib/spatial_grid.js';
import { drawTree } from '../lib/tree_renderer.js';

const DRAG_SLOP = 4;
const CLICK_RADIUS = 30;

export default class extends Controller {
    static targets = ['canvas', 'state'];
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

        for (const [id, name, kind, ascendancy, x, y] of tree.nodes) {
            // Another ascendancy's nodes are not reachable by this build and
            // would only be clutter around the part that is.
            if (ascendancy && ascendancy !== this.ascendancy) {
                continue;
            }

            this.nodes.set(id, { id, name, kind, x, y });
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
    }

    pointermove(event) {
        if (this.dragging) {
            const dx = event.offsetX - this.dragging.x;
            const dy = event.offsetY - this.dragging.y;

            this.dragging.moved += Math.abs(dx) + Math.abs(dy);
            this.dragging.x = event.offsetX;
            this.dragging.y = event.offsetY;
            this.camera = panBy(this.camera, dx, dy);
            this.redraw();

            return;
        }

        const node = this.nodeAt(event.offsetX, event.offsetY);
        const id = node?.id ?? null;

        if (id !== this.hovered) {
            this.hovered = id;
            this.canvasTarget.title = node ? `${node.name} (${node.id})` : '';
            this.redraw();
        }
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

    async toggle(id) {
        const allocating = !this.allocated.has(id);

        // Draw the change at once and put it back if the server refuses:
        // waiting a round trip before the node lights up makes the tree feel
        // broken on a slow connection.
        if (allocating) {
            this.allocated.add(id);
        } else {
            this.allocated.delete(id);
        }

        this.redraw();

        const body = new URLSearchParams({ action: allocating ? 'passive.allocate' : 'passive.deallocate', id });
        const response = await fetch(this.actUrlValue, {
            method: 'POST',
            headers: { Accept: 'text/vnd.turbo-stream.html' },
            body,
        });

        if (!response.ok && response.status !== 422) {
            if (allocating) {
                this.allocated.delete(id);
            } else {
                this.allocated.add(id);
            }

            this.redraw();

            return;
        }

        Turbo.renderStreamMessage(await response.text());
    }
}
