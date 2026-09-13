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

// e2e_leaf hangs off e2e_target (not off the start), so allocating both makes
// the target a junction whose removal must strand the leaf too.
const LEAF_OFFSET_X = 2000;
// e2e_island carries no edge at all — a click there must be refused as not
// connected.
const ISLAND_OFFSET_X = -1000;

function createBuild() {
    return execSync('APP_ENV=test php bin/console app:test:build').toString().trim();
}

/**
 * Clicks the canvas at the point `worldOffsetX` world units right of the
 * start node the camera centres on (negative moves left) — the same
 * geometry `CLICK_OFFSET_PX` computes inline above, extracted here because
 * the two tests below click three or more distinct points between them.
 * Callers must have already called `canvas.scrollIntoViewIfNeeded()`, since
 * `page.mouse.click()` sends raw viewport coordinates and never scrolls.
 */
async function clickCanvasAt(page, canvas, worldOffsetX) {
    const box = await canvas.boundingBox();
    await page.mouse.click(box.x + box.width / 2 + worldOffsetX * SCALE, box.y + box.height / 2);
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

    // Polling here, rather than a single synchronous read right after the
    // click, is what makes this reliable regardless of how long the allocate
    // request and its Turbo Stream render take — the same swap that fixed a
    // flaky read of #build-state further down this file.
    await expect.poll(() => allocated.textContent()).not.toBe(before ?? '');
    await expect.poll(() => nodes.textContent()).toContain(TARGET_NODE_ID);
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

test('the weapon set chosen above the canvas is the one a click allocates into', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    await page.getByRole('radio', { name: 'Weapon set 1' }).check();

    // Same point the other tests click — the seeded target node, CLICK_OFFSET_PX
    // right of the start node the camera centres on.
    const box = await canvas.boundingBox();
    await page.mouse.click(box.x + box.width / 2 + CLICK_OFFSET_PX, box.y + box.height / 2);

    // The radio survives the Turbo Stream: it lives in the section header,
    // outside the <turbo-stream> that replaces the canvas's own contents.
    await expect(page.getByRole('radio', { name: 'Weapon set 1' })).toBeChecked();

    // History renders which weapon set an allocation went into (_history.html.twig),
    // so this is a real, human-visible confirmation that the click was routed
    // into set 1 rather than shared.
    await expect(page.locator('#build-history')).toContainText('weapon set 1');

    // Checking only the DOM text would not be enough on its own — the previous
    // slice shipped a tooltip whose *text* assertion stayed green while its
    // *position* was wrong. Here, the group membership is asserted directly
    // from the state the canvas itself reads to decide what colour to paint:
    // the target node must be in the "1" group and nowhere else.
    const state = JSON.parse(await page.locator('#build-state').textContent());
    expect(state.allocatedBySet['1']).toContain(TARGET_NODE_ID);
    expect(state.allocatedBySet.shared).not.toContain(TARGET_NODE_ID);
    expect(state.allocatedBySet['2']).not.toContain(TARGET_NODE_ID);
});

test('the chosen weapon set survives back-navigation, and a click after it still uses it', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    await page.getByRole('radio', { name: 'Weapon set 1' }).check();

    // Tried page.reload() first, as literally asked: in this Chromium/
    // Playwright combination it does NOT restore a checked radio — the page
    // comes back with the server-rendered default ('shared') checked again,
    // so a reload here cannot exercise "the DOM disagrees with connect()'s
    // default" at all (confirmed by running it against both the buggy and
    // the fixed connect() — same outcome either way). Real back-navigation
    // does restore it: the "Find a node" search is a plain GET, so it is a
    // full Turbo Drive visit, and Turbo's own page cache restores the exact
    // DOM — including the live checked radio — when the browser goes back to
    // it. Confirmed this fails against the pre-fix hardcoded 'shared' (the
    // click landed in "shared" instead of "1") and passes against the fix.
    await page.locator('#passive-q').fill('anything');
    await page.locator('#passive-q').press('Enter');
    await page.waitForURL(/[?&]q=anything/);
    await page.goBack();
    await page.waitForURL((currentUrl) => !currentUrl.toString().includes('q=anything'));

    const canvasAfterBack = page.locator('canvas.tree-canvas');
    await expect(canvasAfterBack).toBeVisible();
    await expect.poll(() => canvasAfterBack.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvasAfterBack.scrollIntoViewIfNeeded();

    // Confirms the browser really did restore it, before blaming the
    // controller for anything.
    await expect(page.getByRole('radio', { name: 'Weapon set 1' })).toBeChecked();

    const box = await canvasAfterBack.boundingBox();
    await page.mouse.click(box.x + box.width / 2 + CLICK_OFFSET_PX, box.y + box.height / 2);

    // The allocate request and its Turbo Stream render are asynchronous —
    // polling (rather than a single synchronous read right after the click)
    // is what makes this reliable regardless of how long that round trip takes.
    await expect.poll(async () => {
        const state = JSON.parse(await page.locator('#build-state').textContent());

        return state.allocatedBySet['1'].includes(TARGET_NODE_ID);
    }).toBe(true);

    const state = JSON.parse(await page.locator('#build-state').textContent());
    expect(state.allocatedBySet.shared).not.toContain(TARGET_NODE_ID);
    expect(state.allocatedBySet['2']).not.toContain(TARGET_NODE_ID);
});

test('an illegal node cannot be allocated from the canvas', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    const nodes = page.locator('#build-nodes');
    const before = await nodes.textContent();

    // e2e_island (CreateTestBuildCommand) carries no edge at all. A click
    // that misses every node produces the same "nothing happened" as a
    // correct refusal, so the before/after comparison on #build-nodes is what
    // tells the two apart — only the error message proves this was a real
    // refusal rather than a miss.
    await clickCanvasAt(page, canvas, ISLAND_OFFSET_X);

    await expect(page.locator('.error')).toContainText('not connected');
    await expect(nodes).toHaveText(before ?? '');
});

test('a canvas click keeps the passive search and the stats view', async ({ page }) => {
    // "End-to-end leaf" names exactly one seeded node (CreateTestBuildCommand),
    // so the search rings e2e_leaf, and stats=1 opens the weapon set 1 view.
    // The canvas's own POST carries neither in its body: only the page's query
    // string can keep them alive through the Turbo Stream it gets back.
    await page.goto(`${createBuild()}?q=${encodeURIComponent('End-to-end leaf')}&stats=1`);

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    const allocated = page.locator('#build-nodes h3').filter({ hasText: 'What the tree gives' });
    await expect(allocated).toContainText('with weapon set 1');
    await expect(allocated).toContainText('(3 passives)');

    await clickCanvasAt(page, canvas, TARGET_OFFSET_X);

    // The count moving is what proves the stream has landed. Reading the
    // search and the view before that would only read the page as it was
    // first served, which already carries both.
    await expect.poll(() => allocated.textContent()).toContain('(4 passives)');

    const state = JSON.parse(await page.locator('#build-state').textContent());
    expect(state.highlighted).toContain('e2e_leaf');
    await expect(page.locator('#passive-q')).toHaveValue('End-to-end leaf');
    await expect(allocated).toContainText('with weapon set 1');
});

test('removing a junction takes its branch with it', async ({ page }) => {
    await page.goto(createBuild());

    const canvas = page.locator('canvas.tree-canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((el) => el.width)).toBeGreaterThan(0);
    await canvas.scrollIntoViewIfNeeded();

    // #build-nodes carries three headings (Levels, the stats overview,
    // Instilled) — only the stats overview's count changes on allocation, so
    // this picks it out by its stable "What the tree gives" prefix, same as
    // the first test in this file.
    const nodes = page.locator('#build-nodes');
    const allocated = nodes.locator('h3').filter({ hasText: 'What the tree gives' });

    // valid-full.build (the fixture app:test:build loads) already carries 3
    // passives — strength89, melee22_, attributes70 — that this seeded
    // catalog has never heard of, so they are illegal from the very moment
    // the build is created, independently of anything this test does. The
    // count therefore runs 3 -> 5 -> 3 below, not to 0: landing back at 3
    // rather than 0 after the removal is the browser-level proof that a
    // removal no longer sweeps passives that were already illegal before it
    // ran.
    await expect.poll(() => allocated.textContent()).toContain('3 passives');

    // e2e_target hangs one edge off the start; allocating it, then e2e_leaf
    // which hangs off *it*, makes the target a junction.
    await clickCanvasAt(page, canvas, TARGET_OFFSET_X);
    await expect.poll(() => allocated.textContent()).toContain('4 passives');

    await clickCanvasAt(page, canvas, LEAF_OFFSET_X);
    await expect.poll(() => allocated.textContent()).toContain('5 passives');

    // Removing the junction must take the leaf with it.
    await clickCanvasAt(page, canvas, TARGET_OFFSET_X);
    await expect.poll(() => allocated.textContent()).toContain('3 passives');
    await expect(page.locator('#build-history')).toContainText('and 1 more');
});
