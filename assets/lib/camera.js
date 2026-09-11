// Pan and zoom for the passive tree.
//
// A camera is {scale, x, y}, where x/y is the world point sitting at the
// canvas centre. Cameras are values: every function here returns a new one
// rather than mutating, so a failed edit can put the old one back.

export function createCamera({ scale = 0.1, x = 0, y = 0 } = {}) {
    return { scale, x, y };
}

export function worldToScreen(camera, width, height, wx, wy) {
    return {
        x: (wx - camera.x) * camera.scale + width / 2,
        y: (wy - camera.y) * camera.scale + height / 2,
    };
}

export function screenToWorld(camera, width, height, sx, sy) {
    return {
        x: (sx - width / 2) / camera.scale + camera.x,
        y: (sy - height / 2) / camera.scale + camera.y,
    };
}

// Zooming about a point: the world point under the cursor must not move, or
// the tree slides away from wherever the player is looking.
export function zoomAt(camera, width, height, sx, sy, factor, min = 0.02, max = 2) {
    const before = screenToWorld(camera, width, height, sx, sy);
    const scale = Math.min(max, Math.max(min, camera.scale * factor));
    const after = screenToWorld({ ...camera, scale }, width, height, sx, sy);

    return { scale, x: camera.x + (before.x - after.x), y: camera.y + (before.y - after.y) };
}

export function panBy(camera, dxScreen, dyScreen) {
    return { scale: camera.scale, x: camera.x - dxScreen / camera.scale, y: camera.y - dyScreen / camera.scale };
}
