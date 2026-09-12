<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Build;
use App\Entity\BuildEvent;
use App\Tests\Support\SeedsALegalPassiveTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BuildEditorControllerTest extends WebTestCase
{
    use SeedsALegalPassiveTree;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.BuildEvent::class.' e')->execute();
        $entityManager->createQuery('DELETE FROM '.Build::class.' b')->execute();
    }

    public function testAPlainFormPostChangesTheBuildAndRedirectsBack(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Respec at 60']);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorExists('input[value="Respec at 60"]');
    }

    public function testAHeaderFieldCanBeClearedRatherThanBeingRefused(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Before']);
        $this->client->followRedirect();
        self::assertSelectorExists('input[value="Before"]');

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => '']);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorNotExists('input[value="Before"]');
    }

    public function testATurboRequestGetsStreamsInsteadOfARedirect(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'From Turbo'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['build-header', 'build-nodes', 'build-history', 'build-state', 'build-error'] as $target) {
            self::assertStringContainsString('target="'.$target.'"', $body);
        }
    }

    public function testARefusedEditAnswers422AndSaysWhyRatherThanFailing(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'target_level', 'value' => '900'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('target level', (string) $this->client->getResponse()->getContent());
    }

    public function testTheGameVersionCanBeChanged(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'game_version', 'value' => '0.6.0']);
        $this->client->followRedirect();

        self::assertSelectorExists('input[name="value"][value="0.6.0"]');
    }

    public function testAnEmptyGameVersionIsRefused(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'game_version', 'value' => ''], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        // "This build has no game version to change" would also satisfy a
        // bare 422 check — it is the wrong message, meant for an unknown
        // field rather than a missing value, so the text is asserted too.
        self::assertStringContainsString('game version must be set', (string) $this->client->getResponse()->getContent());
    }

    public function testEditingWithoutTheEditTokenIsRefused(): void
    {
        $slug = $this->slugOf($this->createBuild());

        $this->client->request('POST', '/b/'.$slug.'/edit/'.str_repeat('0', 64).'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Hijacked']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPassiveCanBeAllocatedAndRemovedWithoutJavascript(): void
    {
        $edit = $this->createBuild(['melee99_']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'melee99_');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.deallocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextNotContains('#build-nodes', 'melee99_');
    }

    public function testAllocatingAPassiveIsRefusedWithoutAClass(): void
    {
        // The node genuinely exists in the catalog — this refusal is about
        // the build having no class to root a start node on, not about the
        // node being unknown (that is a different failure, covered above by
        // `far` in `testAllocatingANodeThatTouchesNothingIsRefused()`).
        $edit = $this->createBuild();
        $db = self::getContainer()->get(Connection::class);
        $this->seedALegalPassiveTree($db, ['unreachable_without_class']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'unreachable_without_class'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not connected', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', $edit);
        self::assertStringNotContainsString('unreachable_without_class', $crawler->filter('#build-nodes')->text(), 'a refused allocation must not reach the document');
    }

    public function testAllocatingANodeThatTouchesNothingIsRefused(): void
    {
        $edit = $this->seedBuildWithTree();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'far'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not connected', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', $edit);
        self::assertStringNotContainsString('far', $crawler->filter('#build-nodes')->text(), 'a refused allocation must not reach the document');
    }

    public function testTheCanvasStateSeparatesTheThreeAllocationGroups(): void
    {
        $edit = $this->seedBuildWithTree();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'near']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'leaf', 'set' => '1']);

        $crawler = $this->client->request('GET', $edit);
        $state = json_decode($crawler->filter('#build-state')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        $allocatedBySet = $state['allocatedBySet'];
        self::assertIsArray($allocatedBySet);
        $shared = $allocatedBySet['shared'];
        self::assertIsArray($shared);
        $one = $allocatedBySet['1'];
        self::assertIsArray($one);
        $two = $allocatedBySet['2'];
        self::assertIsArray($two);

        // `seedBuildWithTree()` builds from `valid-full.build`, which already
        // carries its own weapon-set-1 and weapon-set-2 entries
        // (`melee22_`, `attributes70`) — so set 2 is not asserted empty, only
        // that this test's own two allocations landed in the groups the
        // request named.
        self::assertContains('near', $shared);
        self::assertContains('leaf', $one);
        self::assertNotContains('near', $two);
        self::assertNotContains('leaf', $two);
        self::assertSame('start', $state['startNodeId']);
    }

    public function testDeallocatingAJunctionTakesWhatHungOffIt(): void
    {
        $edit = $this->seedBuildWithTree();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'near']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'leaf']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.deallocate', 'id' => 'near']);

        $crawler = $this->client->request('GET', $edit);

        self::assertStringNotContainsString('leaf', $crawler->filter('#build-nodes')->text(), 'the leaf lost its only route to the start');
        self::assertStringContainsString('and 1 more', $crawler->filter('#build-history')->text());
    }

    public function testDeallocatingDoesNotSweepPassivesThatWereAlreadyIllegal(): void
    {
        // `seedBuildWithTree()` builds from `valid-full.build`, which already
        // carries `strength89` — a passive this synthetic catalog has never
        // heard of, so it is illegal from the moment the build is created,
        // independently of anything this test does. A real imported build
        // can carry passives like this for real (this app models neither
        // socket-radius jewels nor every unlock path), so removing an
        // unrelated junction must not take it: only what the removal itself
        // strands belongs in the cascade.
        $edit = $this->seedBuildWithTree();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'near']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'leaf']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.deallocate', 'id' => 'near']);

        $crawler = $this->client->request('GET', $edit);

        self::assertStringContainsString('strength89', $crawler->filter('#build-nodes')->text(), 'a passive already illegal before the edit must survive an unrelated removal');
        self::assertStringContainsString('and 1 more', $crawler->filter('#build-history')->text());
        self::assertStringNotContainsString('and 2 more', $crawler->filter('#build-history')->text());
    }

    public function testASkillCanBeAddedGivenASupportAndRemovedAgain(): void
    {
        $edit = $this->createBuild();

        // The fixture's second skill is already SkillGemHatefulFocus (index 1), so a
        // distinct id is used here — otherwise the final "gone" assertion below would
        // still match that untouched original entry and the test could never fail.
        $this->client->request('POST', $edit.'/act', ['action' => 'skill.add', 'gem_id' => 'Metadata/Items/Gem/SkillGemFrostBlades']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#build-skills', 'Metadata/Items/Gem/SkillGemFrostBlades');

        $this->client->request('POST', $edit.'/act', ['action' => 'support.add', 'skill_index' => '2', 'support_id' => 'Metadata/Items/Gems/SupportGemFastForward']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#build-skills', 'SupportGemFastForward');

        $this->client->request('POST', $edit.'/act', ['action' => 'skill.remove', 'index' => '2']);
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('#build-skills', 'FrostBlades');
    }

    public function testASupportCanBeFoundBySearchRatherThanTyped(): void
    {
        $edit = $this->createBuild();

        // The catalog tables are empty in this test database — the controller
        // tests elsewhere never rely on a real match, only on the term
        // surviving. This assertion needs an actual hit, so one is seeded.
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM catalog_gem WHERE id = 'Metadata/Items/Gems/SupportGemFastForward'");
        $connection->executeStatement(
            "INSERT INTO catalog_gem (id, name, kind, primary_attribute, icon) VALUES ('Metadata/Items/Gems/SupportGemFastForward', 'Fast Forward', 'support', 'dexterity', NULL)",
        );

        try {
            $this->client->request('GET', $edit.'?support=Fast');

            // `input[name="support"]` alone matches both the search box and
            // the hidden `_search_state` field that every /act form on this
            // page carries to keep the term alive — neither is specific to a
            // search actually having found something. The fixture's first
            // skill already has this same gem as a support, so even
            // `support_id="...FastForward"` alone is not enough: its
            // "Remove support" form carries that id too. Only the
            // "support.add" action, which the search result's own form
            // produces, proves the search actually found something.
            self::assertSelectorExists('#build-skills form input[name="action"][value="support.add"]');
        } finally {
            $connection->executeStatement("DELETE FROM catalog_gem WHERE id = 'Metadata/Items/Gems/SupportGemFastForward'");
        }
    }

    public function testASupportSearchSurvivesAPlainFormPostAndRedirect(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'skill.interval', 'index' => '0', 'from' => '1', 'to' => '100', 'support' => 'Fast']);
        $this->client->followRedirect();

        self::assertSelectorExists('#support-q[value="Fast"]');
    }

    public function testASkillIntervalCascadesToItsSupportsWhenTheyFollow(): void
    {
        $edit = $this->createBuild();

        // The fixture's two supports start off at different ranges from their
        // skill ([12,100] and [34,100] against the skill's [12,100]), so
        // "the skill's own from is 12" is already true before the cascade —
        // asserting only that would pass without the feature. The rendered
        // "Follows the skill's range" line names the numbers a support
        // actually landed on, so it can only appear once the cascade moved
        // both supports onto the skill's new range.
        $this->client->request('POST', $edit.'/act', ['action' => 'skill.interval_cascade', 'index' => '0', 'from' => '12', 'to' => '90']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-skills', "Follows the skill's range (12–90)");
    }

    public function testAUniqueCanBeNamedForASlotAndClearedAgain(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'slot.set', 'inventory_id' => 'Ring1', 'unique_name' => 'Kalandra\'s Touch', 'from' => '1', 'to' => '100', 'additional_text' => '']);
        $this->client->followRedirect();
        self::assertSelectorExists('#slot-name-Ring1[value="Kalandra\'s Touch"]');

        $this->client->request('POST', $edit.'/act', ['action' => 'slot.clear', 'inventory_id' => 'Ring1']);
        $this->client->followRedirect();
        self::assertSelectorNotExists('#slot-name-Ring1[value="Kalandra\'s Touch"]');
    }

    public function testAllFourteenSlotsAreOffered(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertSelectorCount(14, '#build-slots form[data-slot]');
    }

    public function testAUniqueCanBeFoundBySearch(): void
    {
        $this->seedUnique('test_unique_moonshard', 'Test Moonshard Halo');

        try {
            $this->client->request('GET', $this->createBuild().'?unique=Moonshard');

            self::assertSelectorTextContains('#build-slots', 'Test Moonshard Halo');
            self::assertSelectorExists('#build-slots select[name="inventory_id"]');
        } finally {
            $this->forgetUnique('test_unique_moonshard');
        }
    }

    public function testTheInstilledFieldSitsWithTheAmulet(): void
    {
        $this->seedInstillablePassive('test_instill_ember', 'Test Ember Ward', ['TestLiquidAwe', 'TestLiquidGrief', 'TestLiquidJoy']);

        try {
            $this->client->request('GET', $this->createBuild().'?instilled=Ember');

            self::assertSelectorTextContains('#build-slots', 'Test Ember Ward');
            self::assertSelectorTextContains('#build-slots', 'Test Liquid Awe');
        } finally {
            $this->forgetInstillablePassive('test_instill_ember');
        }
    }

    public function testAnInstillableNodeCanBeDeclaredFromTheAmulet(): void
    {
        $this->seedInstillablePassive('test_instill_frost', 'Test Frost Ward', ['TestLiquidCalm']);

        try {
            $edit = $this->createBuild();

            $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'test_instill_frost']);
            $this->client->followRedirect();

            self::assertSelectorExists('h3#build-instilled + p + ul li:contains("Test Frost Ward")');
        } finally {
            $this->forgetInstillablePassive('test_instill_frost');
        }
    }

    public function testAnUnresolvedInstilledIdStaysVisibleAndRemovable(): void
    {
        // Declares an id no catalog row backs — a build can outlive a
        // catalog re-sync. It must not disappear, and it must still be
        // removable even though nothing is known about it beyond its id.
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'no_such_instilled_id']);
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('h3#build-instilled + p + ul', 'no_such_instilled_id');
        $removeForm = $crawler->filter('h3#build-instilled + p + ul form')->reduce(
            static fn ($form) => 'instilled.remove' === $form->filter('input[name="action"]')->attr('value')
                && 'no_such_instilled_id' === $form->filter('input[name="id"]')->attr('value'),
        );
        self::assertGreaterThan(0, $removeForm->count(), 'an unresolved id must still carry a working Remove control');

        $this->client->request('POST', $edit.'/act', ['action' => 'instilled.remove', 'id' => 'no_such_instilled_id']);
        $this->client->followRedirect();

        self::assertSelectorTextNotContains('h3#build-instilled + p + ul', 'no_such_instilled_id');
    }

    public function testTheEditorStillOffersTheWholeFileReplacement(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/update"]');
        self::assertSelectorExists('#build-findings');
    }

    public function testAnEditCanBeRevertedFromTheHistoryPanel(): void
    {
        $edit = $this->createBuild(['first', 'second']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'first']);
        $crawler = $this->client->followRedirect();
        $eventId = $crawler->filter('#build-history button[name="event_id"]')->first()->attr('value');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'second']);
        $this->client->followRedirect();

        $this->client->request('POST', $edit.'/act', ['action' => 'history.revert', 'event_id' => (string) $eventId]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'first');
        self::assertSelectorTextNotContains('#build-nodes', 'second');
        self::assertSelectorTextContains('#build-history', 'Reverted');
    }

    public function testANodeSearchSurvivesAPlainFormPostAndRedirect(): void
    {
        $edit = $this->createBuild(['melee1_']);

        // The search page itself already carries the term forward into its
        // /act forms as a hidden field — confirm that before relying on it.
        $this->client->request('GET', $edit.'?q=crit');
        self::assertSelectorExists('input[name="q"][value="crit"]');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee1_', 'q' => 'crit']);
        $this->client->followRedirect();

        self::assertSelectorExists('#passive-q[value="crit"]');
    }

    public function testANodeSearchSurvivesInTheTurboStreamResponse(): void
    {
        $edit = $this->createBuild(['melee1_']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee1_', 'q' => 'crit'], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('id="passive-q" name="q" value="crit"', (string) $this->client->getResponse()->getContent());
    }

    public function testTheOverviewGroupsAllocatedPassivesByFamily(): void
    {
        // A build straight off the fixture is already staggered (see the interval
        // tests below), which would open the per-passive editor and print each id
        // there regardless of grouping. Starting from a fresh, uniform build keeps
        // that second section collapsed, so the only place an id could leak from
        // is the summary this test actually exercises.
        $edit = $this->pastedBuild('{"name":"Uniform"}', ['criticals7', 'criticals38']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'criticals7']);
        $this->client->followRedirect();
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'criticals38']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'criticals');
        self::assertSelectorTextNotContains('#build-nodes', 'criticals7', 'the family is shown, not each id');
    }

    public function testTheOverviewOffersOneIntervalWhenTheyAreUniform(): void
    {
        // The fixture build is staggered on purpose (strength89 [1,100], melee22_
        // [34,60], attributes70 [0,100]) so it cannot stand in for the uniform
        // case here — a fresh build with two default-interval allocations does.
        $edit = $this->pastedBuild('{"name":"Uniform"}', ['strength89', 'melee22_']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'strength89']);
        $this->client->followRedirect();
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee22_']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes form[data-interval="all"]');
        self::assertSelectorNotExists('#build-nodes form[data-interval="one"]');
    }

    public function testStaggeredIntervalsOpenThePerPassiveEditor(): void
    {
        // Same uniform starting point as above, then one passive is staggered so
        // the assertion below has an actual difference to detect — the fixture
        // build is already staggered before passive.interval touches anything.
        $edit = $this->pastedBuild('{"name":"Uniform"}', ['strength89', 'melee22_']);

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'strength89']);
        $this->client->followRedirect();
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee22_']);
        $this->client->followRedirect();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.interval', 'id' => 'strength89', 'from' => '34', 'to' => '60']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes form[data-interval="one"]');
    }

    public function testSettingTheTreeWideIntervalTouchesEveryPassive(): void
    {
        // The fixture build starts staggered ([1,100], [34,60], [0,100]), so
        // "some input named from has value 12" would also be true if the
        // action had only touched strength89 and left the rest staggered —
        // that passive's own per-passive field would still read 12. Only the
        // whole-tree form renders at all once every passive actually shares
        // the new range, so its presence is what proves every passive moved.
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.interval_all', 'from' => '12', 'to' => '90']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes form[data-interval="all"] input[name="from"][value="12"]');
    }

    public function testTheIntervalsQueryParameterOverridesTheDerivedPassiveMode(): void
    {
        // A fresh, uniform build derives to the flat, whole-tree mode.
        $edit = $this->pastedBuild('{"name":"Uniform"}', ['strength89', 'melee22_']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'strength89']);
        $this->client->followRedirect();
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee22_']);
        $this->client->followRedirect();

        $this->client->request('GET', $edit);
        self::assertSelectorExists('#build-nodes form[data-interval="all"]');

        // The derived mode is never stored, so overriding it is a one-way
        // door only in the sense that no toggle existed at all before — the
        // query parameter must be able to force per-passive editing on data
        // that is still, in fact, uniform.
        $this->client->request('GET', $edit.'?intervals=per-passive');
        self::assertSelectorExists('#build-nodes form[data-interval="one"]');
        self::assertSelectorNotExists('#build-nodes form[data-interval="all"]');
    }

    public function testTheIntervalsOverrideSurvivesAPlainFormPostAndRedirect(): void
    {
        $edit = $this->pastedBuild('{"name":"Uniform"}', ['strength89', 'melee22_']);
        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'strength89']);
        $this->client->followRedirect();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee22_', 'intervals' => 'per-passive']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes form[data-interval="one"]');
    }

    public function testTheSupportsQueryParameterOverridesTheDerivedSupportMode(): void
    {
        // The fixture's first skill has two supports at different ranges from
        // each other and from their skill, so the derived mode lets each be
        // edited on its own — "Follows the skill's range" does not appear.
        $edit = $this->createBuild();

        $this->client->request('GET', $edit);
        self::assertSelectorTextNotContains('#build-skills', "Follows the skill's range");

        $this->client->request('GET', $edit.'?supports=flat');
        self::assertSelectorTextContains('#build-skills', "Follows the skill's range");
    }

    public function testInstilledNodesListSeparatelyFromTheTree(): void
    {
        $this->seedInstillablePassive('test_instill_apart', 'Test Apart Ward', ['TestLiquidCalm']);

        try {
            $edit = $this->createBuild();

            $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'test_instill_apart']);
            $this->client->followRedirect();

            self::assertSelectorTextContains('h3#build-instilled + p + ul', 'Test Apart Ward');
        } finally {
            $this->forgetInstillablePassive('test_instill_apart');
        }
    }

    public function testTheHistoryPanelDescribesTheNewestEditActionsRatherThanPrintingTheirKey(): void
    {
        $this->seedInstillablePassive('test_instill_history', 'Test History Ward', ['TestLiquidCalm']);

        try {
            $edit = $this->createBuild();

            $this->client->request('POST', $edit.'/act', ['action' => 'passive.interval_all', 'from' => '12', 'to' => '90']);
            $this->client->followRedirect();
            $this->client->request('POST', $edit.'/act', ['action' => 'skill.interval_cascade', 'index' => '0', 'from' => '12', 'to' => '90']);
            $this->client->followRedirect();
            $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'test_instill_history']);
            $this->client->followRedirect();
            $this->client->request('POST', $edit.'/act', ['action' => 'instilled.remove', 'id' => 'test_instill_history']);
            $this->client->followRedirect();

            self::assertSelectorTextContains('#build-history', 'Set one range for the whole tree');
            self::assertSelectorTextContains('#build-history', 'Changed when a skill and its supports are used');
            self::assertSelectorTextContains('#build-history', 'Declared an Instilled Modifier');
            self::assertSelectorTextContains('#build-history', 'Removed an Instilled Modifier');
            foreach (['passive.interval_all', 'skill.interval_cascade', 'instilled.add', 'instilled.remove'] as $rawAction) {
                self::assertSelectorTextNotContains('#build-history', $rawAction);
            }
        } finally {
            $this->forgetInstillablePassive('test_instill_history');
        }
    }

    public function testASnapshotCanBeNamedAndIsMarkedAsKept(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'snapshot.create', 'name' => 'Before the respec']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-history', 'Before the respec');
        self::assertSelectorTextContains('#build-history', 'kept');
    }

    /**
     * @param list<string> $legalPassiveIds ids the caller is about to allocate;
     *                                       when given, a legal star tree is seeded
     *                                       for them and assigned to the build
     *
     * @return string the edit URL, without a trailing slash
     */
    private function createBuild(array $legalPassiveIds = []): string
    {
        $source = __DIR__.'/../fixtures/build/valid-full.build';
        $copy = sys_get_temp_dir().'/'.uniqid('upload', true).'.build';
        copy($source, $copy);

        $this->client->request('POST', '/builds', files: ['build' => new UploadedFile($copy, 'valid-full.build', 'application/json', test: true)]);
        $edit = (string) $this->client->getResponse()->headers->get('Location');

        if ([] !== $legalPassiveIds) {
            $this->assignLegalClass($edit, $legalPassiveIds);
        }

        return $edit;
    }

    /**
     * @param list<string> $legalPassiveIds see {@see createBuild()}
     *
     * @return string the edit URL, without a trailing slash
     */
    private function pastedBuild(string $json, array $legalPassiveIds = []): string
    {
        $this->client->request('POST', '/builds', ['json' => $json]);
        $edit = (string) $this->client->getResponse()->headers->get('Location');

        if ([] !== $legalPassiveIds) {
            $this->assignLegalClass($edit, $legalPassiveIds);
        }

        return $edit;
    }

    /**
     * Since Task 8, allocating a passive is refused unless it is connected to
     * the build's class's start node — real product behaviour, not a test
     * inconvenience (see `testAllocatingAPassiveIsRefusedWithoutAClass()`).
     * Tests that allocate a passive only incidentally, on the way to testing
     * something else, need a legal tree under the ids they use; this seeds
     * one and assigns its class to the given build.
     *
     * @param list<string> $ids
     */
    private function assignLegalClass(string $edit, array $ids): void
    {
        $db = self::getContainer()->get(Connection::class);
        $classId = $this->seedALegalPassiveTree($db, $ids);

        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'class_key', 'value' => $classId]);
    }

    /**
     * A dedicated chain — start — near — leaf — plus an unconnected far, for
     * the two tests below that care about the tree's shape: one needs a node
     * with no route to the start, the other needs removing a junction to
     * strand something hanging off it. `seedALegalPassiveTree()`'s star
     * cannot express either, so this graph is seeded on its own.
     *
     * @return string the edit URL, without a trailing slash
     */
    private function seedBuildWithTree(): string
    {
        $db = self::getContainer()->get(Connection::class);
        $classId = 'test_chain_class';
        $startNodeId = 'start';
        $ids = [$startNodeId, 'near', 'leaf', 'far'];
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));

        $db->executeStatement("DELETE FROM catalog_passive_edge WHERE from_id IN ({$placeholders}) OR to_id IN ({$placeholders})", [...$ids, ...$ids]);
        $db->executeStatement("DELETE FROM catalog_passive WHERE id IN ({$placeholders})", $ids);
        $db->executeStatement('DELETE FROM catalog_class WHERE id = ?', [$classId]);

        foreach ($ids as $id) {
            $db->executeStatement(
                "INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, 'small', NULL, 0, 0, '[]', '[]')",
                [$id, $id],
            );
        }

        $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', [$startNodeId, 'near']);
        $db->executeStatement('INSERT INTO catalog_passive_edge (from_id, to_id) VALUES (?, ?)', ['near', 'leaf']);
        // 'far' is left with no edge at all: it touches nothing.

        $db->executeStatement(
            'INSERT INTO catalog_class (id, start_node_id, base_str, base_dex, base_int, ascendancies) VALUES (?, ?, 0, 0, 0, ?)',
            [$classId, $startNodeId, '[]'],
        );

        $edit = $this->createBuild();
        $this->client->request('POST', $edit.'/act', ['action' => 'header.set', 'field' => 'class_key', 'value' => $classId]);

        return $edit;
    }

    /**
     * The catalog tables are empty in this test database, like the gem one
     * `testASupportCanBeFoundBySearchRatherThanTyped` seeds above — a made-up
     * unique, since real game data must not enter this repository. `id` is
     * deleted first so a rerun after an aborted test stays idempotent, and
     * the caller deletes it again in a `finally` once done.
     */
    private function seedUnique(string $id, string $name): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM catalog_unique WHERE id = ?', [$id]);
        $connection->executeStatement(
            'INSERT INTO catalog_unique (id, name, item_class, inventory_width, inventory_height) VALUES (?, ?, ?, 1, 1)',
            [$id, $name, 'Amulet'],
        );
    }

    private function forgetUnique(string $id): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('DELETE FROM catalog_unique WHERE id = ?', [$id]);
    }

    /**
     * A made-up instillable passive — recipe entries are camelCase words so
     * `StatText::emotion()` splits them into readable emotion names.
     *
     * @param list<string> $recipe
     */
    private function seedInstillablePassive(string $id, string $name, array $recipe): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM catalog_passive WHERE id = ?', [$id]);
        $connection->executeStatement(
            'INSERT INTO catalog_passive (id, name, kind, ascendancy_key, pos_x, pos_y, stats, recipe) VALUES (?, ?, ?, NULL, 0, 0, ?, ?)',
            [$id, $name, 'notable', json_encode(['Test stat line']), json_encode($recipe)],
        );
    }

    private function forgetInstillablePassive(string $id): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('DELETE FROM catalog_passive WHERE id = ?', [$id]);
    }

    private function slugOf(string $editUrl): string
    {
        self::assertSame(1, preg_match('#/b/([0-9a-zA-Z]{22})#', $editUrl, $m));

        return $m[1];
    }
}
