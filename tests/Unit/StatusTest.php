<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StatusTest extends TestCase
{
    #[Test]
    public function it_maps_each_status_to_a_colour() : void
    {
        $this->assertSame('blue', Status::SUBMITTED->color());
        $this->assertSame('green', Status::VALID->color());
        $this->assertSame('red', Status::INVALID->color());
        $this->assertSame('gray', Status::CANCELLED->color());
    }

    #[Test]
    public function it_titlecases_the_label() : void
    {
        $this->assertSame('Submitted', Status::SUBMITTED->label());
        $this->assertSame('Valid', Status::VALID->label());
        $this->assertSame('Cancelled', Status::CANCELLED->label());
    }

    #[Test]
    public function it_maps_each_status_to_a_numeric_code() : void
    {
        $this->assertSame(1, Status::SUBMITTED->code());
        $this->assertSame(2, Status::VALID->code());
        $this->assertSame(3, Status::INVALID->code());
        $this->assertSame(4, Status::CANCELLED->code());
    }

    #[Test]
    public function is_matches_value_name_or_enum_instance() : void
    {
        $this->assertTrue(Status::VALID->is('valid'));        // value
        $this->assertTrue(Status::VALID->is('VALID'));        // name
        $this->assertTrue(Status::VALID->is(Status::VALID));  // instance
        $this->assertTrue(Status::VALID->is('invalid', 'VALID')); // any match
        $this->assertFalse(Status::VALID->is('invalid'));
    }

    #[Test]
    public function is_not_is_the_inverse_of_is() : void
    {
        $this->assertTrue(Status::VALID->isNot('invalid'));
        $this->assertFalse(Status::VALID->isNot('valid'));
    }
}
