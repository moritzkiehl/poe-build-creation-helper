// Hit testing for the passive tree.
//
// The tree is 4912 nodes spread over roughly 45,000 by 39,000 world units.
// Walking all of them on every click is wasteful and gets worse on hover, so
// nodes are bucketed into square cells once and only the cells near the
// cursor are searched. A uniform grid rather than a quadtree: the point set is
// static, loaded once, and this is a dozen lines instead of a hundred.

export function buildGrid(nodes, cellSize = 120) {
    const cells = new Map();

    for (const node of nodes) {
        const key = cellKey(node.x, node.y, cellSize);
        const bucket = cells.get(key);

        if (bucket) {
            bucket.push(node);
        } else {
            cells.set(key, [node]);
        }
    }

    return { cellSize, cells };
}

export function nearestNode(grid, x, y, radius) {
    const { cellSize, cells } = grid;
    const cx = Math.floor(x / cellSize);
    const cy = Math.floor(y / cellSize);
    // A radius wider than a cell has to look further than the eight
    // neighbours, or nodes just outside the ring are missed.
    const reach = Math.max(1, Math.ceil(radius / cellSize));

    let best = null;
    let bestDistance = radius * radius;

    for (let ix = cx - reach; ix <= cx + reach; ix++) {
        for (let iy = cy - reach; iy <= cy + reach; iy++) {
            for (const node of cells.get(`${ix}:${iy}`) ?? []) {
                const dx = node.x - x;
                const dy = node.y - y;
                const distance = dx * dx + dy * dy;

                if (distance <= bestDistance) {
                    bestDistance = distance;
                    best = node;
                }
            }
        }
    }

    return best;
}

function cellKey(x, y, cellSize) {
    return `${Math.floor(x / cellSize)}:${Math.floor(y / cellSize)}`;
}
