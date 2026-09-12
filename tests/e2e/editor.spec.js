import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// Mirrors the fixed initial scale tree_controller.js hands createCamera() in
// loadTree() (assets/controllers/tree_controller.js). The camera centres on
// the class's start node, so a node `d` world units away lands `d * SCALE`
// device pixels from the canvas centre — see CreateTestBuildCommand, which
// seeds a node exactly TARGET_OFFSET_X world units to the right of the start
// node it also seeds.
const SCALE = 0.12;
const TARGET_OFFSET_X = 1000;
const CLICK_OFFSET_PX = TARGET_OFFSET_X * SCALE;
const TARGET_NODE_ID = 'e2e_target';

function createBuild() {
    return execSync('APP_ENV=test php bin/console app:test:build').toString().trim();
}

test('a node clicked on the canvas appears in the allocated list', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();

    // The controller sizes the canvas (and only then places the camera and
    // builds the hit-test grid) after its tree.json fetch resolves — an
    // async tail of connect() that page.goto()'s load event does not wait
    // for. A canvas element is visible, and thus clickable, well before that
    // finishes, so waiting on the drawing buffer's width is what actually
    // proves the controller is ready to receive the click below.
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);

    // The canvas sits well down the page, below the default viewport.
    // page.mouse.click() sends raw viewport coordinates and never scrolls,
    // unlike a locator click — without this, boundingBox() below is correct
    // but the click lands on whatever the unscrolled viewport shows instead.
    await canvas.scrollIntoViewIfNeeded();

    // #build-nodes carries three headings now (Levels, the stats overview,
    // Instilled) — only the stats overview's count changes on allocation, so
    // the locator picks that one by its stable "What the tree gives" prefix
    // rather than an unqualified 'h3' that resolves to all three.
    const nodes = page.locator('#build-nodes');
    const allocated = nodes.locator('h3').filter({ hasText: 'What the tree gives' });
    const before = await allocated.textContent();

    // The seeded target node sits CLICK_OFFSET_PX to the right of the start
    // node, which the camera centres on. The start node itself deliberately
    // does not toggle when clicked, so aiming at the exact centre would tell
    // us nothing — this aims squarely at the other node instead.
    const box = await canvas.boundingBox();
    await page.mouse.click(box.x + box.width / 2 + CLICK_OFFSET_PX, box.y + box.height / 2);

    await expect(allocated).not.toHaveText(before ?? '');
    await expect(nodes).toContainText(TARGET_NODE_ID);
});
