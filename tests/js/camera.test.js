import { describe, expect, it } from 'vitest';
import { createCamera, panBy, screenToWorld, worldToScreen, zoomAt } from '../../assets/lib/camera.js';

const WIDTH = 800;
const HEIGHT = 600;

describe('camera', () => {
    it('puts the camera centre in the middle of the canvas', () => {
        const camera = createCamera({ scale: 0.5, x: 100, y: -50 });

        expect(worldToScreen(camera, WIDTH, HEIGHT, 100, -50)).toEqual({ x: 400, y: 300 });
    });

    it('round-trips a point through screen space and back', () => {
        const camera = createCamera({ scale: 0.37, x: 1234, y: -987 });
        const screen = worldToScreen(camera, WIDTH, HEIGHT, -22597, 20196);
        const world = screenToWorld(camera, WIDTH, HEIGHT, screen.x, screen.y);

        expect(world.x).toBeCloseTo(-22597, 6);
        expect(world.y).toBeCloseTo(20196, 6);
    });

    it('leaves the world point under the cursor exactly where it was when zooming', () => {
        const camera = createCamera({ scale: 0.1, x: 0, y: 0 });
        const before = screenToWorld(camera, WIDTH, HEIGHT, 650, 120);

        const zoomed = zoomAt(camera, WIDTH, HEIGHT, 650, 120, 1.25);
        const after = screenToWorld(zoomed, WIDTH, HEIGHT, 650, 120);

        expect(after.x).toBeCloseTo(before.x, 6);
        expect(after.y).toBeCloseTo(before.y, 6);
    });

    it('clamps the zoom to its limits', () => {
        const camera = createCamera({ scale: 0.05, x: 0, y: 0 });

        expect(zoomAt(camera, WIDTH, HEIGHT, 0, 0, 0.1, 0.02, 2).scale).toBe(0.02);
        expect(zoomAt(camera, WIDTH, HEIGHT, 0, 0, 1000, 0.02, 2).scale).toBe(2);
    });

    it('pans by screen pixels, not world units', () => {
        const camera = createCamera({ scale: 0.5, x: 0, y: 0 });

        expect(panBy(camera, 50, -25)).toEqual({ scale: 0.5, x: -100, y: 50 });
    });
});
