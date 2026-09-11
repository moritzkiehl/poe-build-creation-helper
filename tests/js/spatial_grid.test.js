import { describe, expect, it } from 'vitest';
import { buildGrid, nearestNode } from '../../assets/lib/spatial_grid.js';

const nodes = [
    { id: 'a', x: 0, y: 0 },
    { id: 'b', x: 300, y: 0 },
    { id: 'c', x: 0, y: 300 },
    { id: 'edge-of-next-cell', x: 121, y: 0 },
];

describe('spatial grid', () => {
    it('finds the node under the point', () => {
        expect(nearestNode(buildGrid(nodes, 120), 5, 5, 40).id).toBe('a');
    });

    it('finds a node that sits in a neighbouring cell', () => {
        // 119 and 121 fall either side of a 120-unit cell boundary.
        expect(nearestNode(buildGrid(nodes, 120), 119, 0, 40).id).toBe('edge-of-next-cell');
    });

    it('answers null when nothing is within the radius', () => {
        expect(nearestNode(buildGrid(nodes, 120), 1000, 1000, 40)).toBeNull();
    });

    it('picks the closest of two candidates', () => {
        const grid = buildGrid([{ id: 'near', x: 10, y: 0 }, { id: 'far', x: 30, y: 0 }], 120);

        expect(nearestNode(grid, 12, 0, 50).id).toBe('near');
    });

    it('searches far enough out when the radius is larger than a cell', () => {
        expect(nearestNode(buildGrid(nodes, 20), 0, 0, 320).id).toBe('a');
        expect(nearestNode(buildGrid(nodes, 20), 250, 0, 100).id).toBe('b');
    });

    it('handles negative coordinates, which half the tree has', () => {
        const grid = buildGrid([{ id: 'left', x: -22597, y: -19013 }], 120);

        expect(nearestNode(grid, -22590, -19010, 40).id).toBe('left');
    });
});
