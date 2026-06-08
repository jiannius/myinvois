<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Enums\TinType;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TinTypeTest extends TestCase
{
    #[Test]
    public function it_resolves_the_special_lhdn_tins() : void
    {
        $this->assertSame(TinType::GENERAL_PUBLIC, TinType::tryFrom('EI00000000010'));
        $this->assertSame(TinType::FOREIGN_BUYER, TinType::tryFrom('EI00000000020'));
        $this->assertSame(TinType::FOREIGN_SUPPLIER, TinType::tryFrom('EI00000000030'));
        $this->assertSame(TinType::GOVERNMENT, TinType::tryFrom('EI00000000040'));
    }

    #[Test]
    public function a_normal_tin_is_not_a_special_type() : void
    {
        $this->assertNull(TinType::tryFrom('C26561325060'));
        $this->assertNull(TinType::tryFrom(''));
    }

    #[Test]
    public function it_humanises_the_label() : void
    {
        $this->assertSame('General Public', TinType::GENERAL_PUBLIC->label());
        $this->assertSame('Foreign Buyer', TinType::FOREIGN_BUYER->label());
        $this->assertSame('Government', TinType::GOVERNMENT->label());
    }
}
