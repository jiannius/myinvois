<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Boost guideline is host-app-facing documentation compiled with Blade by
 * `boost:install`: every <code-snippet> must sit inside @verbatim / @endverbatim, or Blade
 * would try to interpret the PHP in it.
 */
class BoostGuidelineTest extends TestCase
{
    protected function source() : string
    {
        return file_get_contents(__DIR__.'/../../resources/boost/guidelines/core.blade.php');
    }

    #[Test]
    public function it_compiles_with_blade_and_leaves_every_snippet_verbatim() : void
    {
        $source = $this->source();
        $compiled = Blade::compileString($source);

        $this->assertStringNotContainsString('<?php', $compiled, 'a snippet escaped @verbatim and was compiled');
        $this->assertSame(
            substr_count($source, '<code-snippet'),
            substr_count($compiled, '<code-snippet'),
        );
        $this->assertSame(substr_count($source, '</code-snippet>'), substr_count($source, '<code-snippet'));
    }

    #[Test]
    public function every_code_snippet_is_wrapped_in_verbatim() : void
    {
        $source = $this->source();

        $this->assertSame(substr_count($source, '<code-snippet'), substr_count($source, '@verbatim'));
        $this->assertSame(substr_count($source, '@verbatim'), substr_count($source, '@endverbatim'));
    }

    #[Test]
    public function it_documents_the_queued_flow() : void
    {
        $source = $this->source();

        foreach (['queueSubmission', 'ResolvesMyinvoisClient', 'myinvois:sync', 'sync_schedule', 'pollResolvesTo', 'MyinvoisInFlightException'] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }
}
