import { describe, expect, it, vi } from 'vitest';
import { drawTree } from '../../assets/lib/tree_renderer.js';

function fakeContext() {
    const fills = [];
    return {
        canvas: { width: 400, height: 300 },
        get fillStyle() { return this._fill; },
        set fillStyle(value) { this._fill = value; },
        fills,
        clearRect: vi.fn(),
        beginPath: vi.fn(),
        moveTo: vi.fn(),
        lineTo: vi.fn(),
        stroke: vi.fn(),
        arc: vi.fn(),
        fill: vi.fn(function () { fills.push(this._fill); }),
    };
}

const camera = { scale: 1, x: 0, y: 0 };

function nodesAt(ids) {
    return new Map(ids.map((id, index) => [id, { id, name: id, kind: 'small', x: index * 10, y: 0 }]));
}

describe('drawTree', () => {
    it('paints the three allocation groups in three different colours', () => {
        const context = fakeContext();

        drawTree(context, {
            nodes: nodesAt(['shared_node', 'set_one_node', 'set_two_node']),
            edges: [],
            allocatedBySet: { shared: new Set(['shared_node']), one: new Set(['set_one_node']), two: new Set(['set_two_node']) },
            startNodeId: null,
            hovered: null,
            highlighted: new Set(),
        }, camera);

        // Pins identity, not just distinctness: this fails if set one and set
        // two are swapped, or if shared is painted with either set's colour.
        expect(context.fills).toEqual(['#e8c56a', '#6aa9e8', '#7ad67a']);
    });

    it('paints an unallocated node in neither allocation colour', () => {
        const context = fakeContext();

        drawTree(context, {
            nodes: nodesAt(['lonely']),
            edges: [],
            allocatedBySet: { shared: new Set(), one: new Set(), two: new Set() },
            startNodeId: null,
            hovered: null,
            highlighted: new Set(),
        }, camera);

        expect(context.fills).toEqual(['#6a6a7a']);
    });
});
