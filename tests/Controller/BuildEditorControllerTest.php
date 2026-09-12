<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Build;
use App\Entity\BuildEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BuildEditorControllerTest extends WebTestCase
{
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

    public function testEditingWithoutTheEditTokenIsRefused(): void
    {
        $slug = $this->slugOf($this->createBuild());

        $this->client->request('POST', '/b/'.$slug.'/edit/'.str_repeat('0', 64).'/act', ['action' => 'header.set', 'field' => 'note', 'value' => 'Hijacked']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPassiveCanBeAllocatedAndRemovedWithoutJavascript(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.allocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#build-nodes', 'melee99_');

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.deallocate', 'id' => 'melee99_']);
        $this->client->followRedirect();

        self::assertSelectorTextNotContains('#build-nodes', 'melee99_');
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

    public function testTheEditorStillOffersTheWholeFileReplacement(): void
    {
        $this->client->request('GET', $this->createBuild());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/update"]');
        self::assertSelectorExists('#build-findings');
    }

    public function testAnEditCanBeRevertedFromTheHistoryPanel(): void
    {
        $edit = $this->createBuild();

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
        $edit = $this->createBuild();

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
        $edit = $this->createBuild();

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
        $edit = $this->pastedBuild('{"name":"Uniform"}');

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
        $edit = $this->pastedBuild('{"name":"Uniform"}');

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
        $edit = $this->pastedBuild('{"name":"Uniform"}');

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
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'passive.interval_all', 'from' => '12', 'to' => '90']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-nodes input[name="from"][value="12"]');
    }

    public function testInstilledNodesListSeparatelyFromTheTree(): void
    {
        $edit = $this->createBuild();

        $this->client->request('POST', $edit.'/act', ['action' => 'instilled.add', 'id' => 'strength89']);
        $this->client->followRedirect();

        self::assertSelectorExists('#build-instilled');
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
     * @return string the edit URL, without a trailing slash
     */
    private function createBuild(): string
    {
        $source = __DIR__.'/../fixtures/build/valid-full.build';
        $copy = sys_get_temp_dir().'/'.uniqid('upload', true).'.build';
        copy($source, $copy);

        $this->client->request('POST', '/builds', files: ['build' => new UploadedFile($copy, 'valid-full.build', 'application/json', test: true)]);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * @return string the edit URL, without a trailing slash
     */
    private function pastedBuild(string $json): string
    {
        $this->client->request('POST', '/builds', ['json' => $json]);

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function slugOf(string $editUrl): string
    {
        self::assertSame(1, preg_match('#/b/([0-9a-zA-Z]{22})#', $editUrl, $m));

        return $m[1];
    }
}
