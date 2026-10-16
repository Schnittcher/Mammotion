<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MammotionLiveTest extends TestCase
{
    public function testDefaultsApplyWithoutSavedRows(): void
    {
        $selected = MammotionLive::Selected('[]');
        $this->assertSame(['DEV_ST', 'CHG_ST', 'SELF_CHK', 'BAT_PCT', 'NET_ST', 'WK_PRG', 'KNF_HGT', 'KNF_ST', 'DEV_VER', 'TSK_CHG'], $selected);
    }

    public function testDefaultsApplyForInvalidJson(): void
    {
        $this->assertSame(MammotionLive::Selected('[]'), MammotionLive::Selected('kein json'));
    }

    // Symcon speichert aus der Liste nur den Haken, nicht den Namen: Die Zuordnung läuft über die Reihenfolge.
    public function testRowsWithoutPropertyNameMapByOrder(): void
    {
        $rows = array_fill(0, count(MammotionLive::PROPERTIES), ['Subscribe' => false]);
        $rows[7]['Subscribe'] = true;
        $this->assertSame(['COORD'], MammotionLive::Selected(json_encode($rows)));
    }

    public function testMissingRowKeepsDefault(): void
    {
        $flags = MammotionLive::Flags(json_encode([['Subscribe' => false]]));
        $this->assertFalse($flags[0]);
        $this->assertTrue($flags[1]);
        $this->assertCount(count(MammotionLive::PROPERTIES), $flags);
    }

    public function testNothingSelected(): void
    {
        $rows = array_fill(0, count(MammotionLive::PROPERTIES), ['Subscribe' => false]);
        $this->assertSame([], MammotionLive::Selected(json_encode($rows)));
    }
}
