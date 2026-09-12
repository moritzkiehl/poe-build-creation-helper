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

test('hovering a node shows its effect and instil cost in a tooltip', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    const tooltip = page.locator('.tree-tooltip');
    await expect(tooltip).toBeHidden();

    // Same point the other test clicks — the seeded target node, which the
    // test fixture (CreateTestBuildCommand) now gives both a stat line and a
    // three-emotion recipe, so one hover proves both halves of the tooltip.
    const box = await canvas.boundingBox();
    const targetX = box.x + box.width / 2 + CLICK_OFFSET_PX;
    const targetY = box.y + box.height / 2;
    await page.mouse.move(targetX, targetY);

    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText('End-to-end target');
    await expect(tooltip).toContainText('+10 to Strength');
    // The fixture stores these the way the real catalog does — unspaced,
    // carrying "Liquid" — and relies on TreeExport running StatText::emotion()
    // over them on the way to tree.json. Asserting the spaced form here is
    // what actually proves that pipeline ran, rather than just echoing back
    // whatever the fixture happened to already contain.
    await expect(tooltip).toContainText('Concentrated Liquid Fear');
    await expect(tooltip).toContainText('Liquid Ire');
    await expect(tooltip).toContainText('Isolated Liquid Envy');

    // This particular tooltip (three emotions plus a stat line) is wide
    // enough, at this particular hover point (right of canvas centre), that
    // placing it flush against the cursor would run it past #build-tree's
    // own right edge — exactly the overflow case showTooltip() clamps
    // against. So rather than asserting a cursor-relative position here
    // (which the clamp would legitimately violate), this asserts the clamp
    // itself: the tooltip never extends past the section it lives in, on
    // either the trailing or the bottom edge. `1px` covers the section's own
    // border.
    const tipBoxAtEdge = await tooltip.boundingBox();
    const sectionRect = await page.locator('#build-tree').evaluate((el) => el.getBoundingClientRect());
    expect(tipBoxAtEdge.x + tipBoxAtEdge.width).toBeLessThanOrEqual(sectionRect.right + 1);
    expect(tipBoxAtEdge.y + tipBoxAtEdge.height).toBeLessThanOrEqual(sectionRect.bottom + 1);

    // The start node sits at the canvas centre, far enough from every edge
    // that its own tooltip (just a name, no stats or recipe) never gets
    // clamped — so this point is where cursor-tracking itself can be
    // checked without the edge clamp muddying the result. Hovering it, the
    // tooltip must land close to the cursor: event.offsetX/Y are relative to
    // the canvas, but the tooltip's containing block is the section around
    // it, which puts the <h2> above the canvas — miss that offset and the
    // tooltip renders up near the heading instead, tens of pixels off in
    // both axes. The tolerance stays generous (tens of pixels, not a handful)
    // because the exact +14px cursor offset is an implementation detail.
    const centreX = box.x + box.width / 2;
    const centreY = box.y + box.height / 2;
    await page.mouse.move(centreX, centreY);
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText('End-to-end start');

    const tipBoxAtCentre = await tooltip.boundingBox();
    expect(Math.abs(tipBoxAtCentre.x - centreX)).toBeLessThan(40);
    expect(Math.abs(tipBoxAtCentre.y - centreY)).toBeLessThan(40);

    // Moving off every node again hides it rather than leaving stale content.
    // The corner is far in screen space from both the start node (canvas
    // centre) and the target node (CLICK_OFFSET_PX right of centre), well
    // outside either one's hit radius.
    await page.mouse.move(box.x + 5, box.y + 5);
    await expect(tooltip).toBeHidden();

    // Leaving the canvas entirely — rather than moving to a point still on
    // it — takes a different path: no pointermove ever fires again, only
    // pointerleave. Without binding that event, the tooltip from the hover
    // above would stay on screen indefinitely.
    await page.mouse.move(targetX, targetY);
    await expect(tooltip).toBeVisible();
    await page.mouse.move(box.x - 20, box.y - 20);
    await expect(tooltip).toBeHidden();
});
