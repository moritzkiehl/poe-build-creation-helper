import { worldToScreen } from './camera.js';

const COLOURS = {
    edge: '#3a3a46',
    edgeAllocated: '#c7a54a',
    small: '#6a6a7a',
    notable: '#9a8ad6',
    keystone: '#d67a7a',
    allocated: '#e8c56a',
    start: '#ffffff',
};

const RADIUS = { small: 4, notable: 7, keystone: 9 };

// Edges are straight lines. In the game they follow the orbit they were laid
// out on; matching that is a visual nicety and is deliberately not done here.
export function drawTree(context, { nodes, edges, allocated, startNodeId, hovered }, camera) {
    const { width, height } = context.canvas;

    context.clearRect(0, 0, width, height);
    context.lineWidth = 1.5;

    for (const [from, to] of edges) {
        const a = nodes.get(from);
        const b = nodes.get(to);

        if (!a || !b) {
            continue;
        }

        const start = worldToScreen(camera, width, height, a.x, a.y);
        const end = worldToScreen(camera, width, height, b.x, b.y);

        context.strokeStyle = allocated.has(from) && allocated.has(to) ? COLOURS.edgeAllocated : COLOURS.edge;
        context.beginPath();
        context.moveTo(start.x, start.y);
        context.lineTo(end.x, end.y);
        context.stroke();
    }

    for (const node of nodes.values()) {
        const point = worldToScreen(camera, width, height, node.x, node.y);

        if (point.x < -20 || point.y < -20 || point.x > width + 20 || point.y > height + 20) {
            continue;
        }

        const radius = (RADIUS[node.kind] ?? RADIUS.small) * Math.max(0.6, Math.min(1.6, camera.scale * 6));

        context.fillStyle = node.id === startNodeId
            ? COLOURS.start
            : allocated.has(node.id) ? COLOURS.allocated : (COLOURS[node.kind] ?? COLOURS.small);

        context.beginPath();
        context.arc(point.x, point.y, radius, 0, Math.PI * 2);
        context.fill();

        if (node.id === hovered) {
            context.strokeStyle = COLOURS.start;
            context.stroke();
        }
    }
}
