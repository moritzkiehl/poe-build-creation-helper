<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\Edit\Command\AllocatePassive;
use App\Build\Edit\Command\SetHeaderField;
use App\Build\Edit\Command\SetSlot;
use App\Build\Edit\CommandFactory;
use App\Build\Edit\InvalidEditCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class CommandFactoryTest extends TestCase
{
    private CommandFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new CommandFactory();
    }

    public function testAllocatingAPassiveCarriesTheIdVerbatim(): void
    {
        $command = $this->factory->fromRequest(7, 'passive.allocate', new InputBag(['id' => 'melee22_']));

        self::assertInstanceOf(AllocatePassive::class, $command);
        self::assertSame(7, $command->buildId);
        self::assertSame('melee22_', $command->id);
    }

    public function testAHeaderFieldCarriesItsNameAndValue(): void
    {
        $command = $this->factory->fromRequest(7, 'header.set', new InputBag(['field' => 'note', 'value' => 'Respec at 60']));

        self::assertInstanceOf(SetHeaderField::class, $command);
        self::assertSame('note', $command->field);
        self::assertSame('Respec at 60', $command->value);
    }

    public function testAnEmptySlotValueBecomesNullRatherThanAnEmptyString(): void
    {
        $command = $this->factory->fromRequest(7, 'slot.set', new InputBag(['inventory_id' => 'Ring1', 'unique_name' => '', 'from' => '1', 'to' => '100', 'additional_text' => '']));

        self::assertInstanceOf(SetSlot::class, $command);
        self::assertNull($command->uniqueName);
    }

    public function testClearingAHeaderFieldIsAccepted(): void
    {
        $command = $this->factory->fromRequest(7, 'header.set', new InputBag(['field' => 'note', 'value' => '']));

        self::assertInstanceOf(SetHeaderField::class, $command);
        self::assertSame('note', $command->field);
        self::assertSame('', $command->value);
    }

    public function testChoosingTheEmptyDropdownOptionIsAccepted(): void
    {
        $command = $this->factory->fromRequest(7, 'header.set', new InputBag(['field' => 'class_key', 'value' => '']));

        self::assertInstanceOf(SetHeaderField::class, $command);
        self::assertSame('class_key', $command->field);
        self::assertSame('', $command->value);
    }

    /**
     * The fix for the two tests above narrows the emptiness check on `value`
     * alone. Every other field a command reads through the same helper must
     * still refuse an empty string.
     */
    public function testAnEmptyInventoryIdIsStillRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->factory->fromRequest(7, 'slot.set', new InputBag(['inventory_id' => '', 'unique_name' => '', 'from' => '1', 'to' => '100', 'additional_text' => '']));
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->factory->fromRequest(7, 'passive.detonate', new InputBag([]));
    }

    public function testALevelThatIsNotANumberIsRefused(): void
    {
        $this->expectException(InvalidEditCommand::class);

        $this->factory->fromRequest(7, 'passive.interval', new InputBag(['id' => 'a', 'from' => 'soon', 'to' => '100']));
    }
}
