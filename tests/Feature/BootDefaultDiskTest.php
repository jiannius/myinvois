<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Filesystem\FilesystemManager;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Booting a host app with this package must never touch the default filesystem disk.
 * noki/laravel-xml-converter (a former dependency) called Storage::exists() from its
 * auto-discovered provider on every boot, a remote request when the default disk is S3.
 */
class BootDefaultDiskTest extends TestCase
{
    public static int $diskCalls = 0;

    /**
     * Boot with the discoverable providers of every installed runtime (non-dev) package,
     * as a host app's package discovery would. Testbench's own discovery reads its
     * skeleton's vendor directory, so it is not used here.
     */
    protected function getPackageProviders($app) : array
    {
        $installed = json_decode(file_get_contents(__DIR__.'/../../vendor/composer/installed.json'), true);
        $dev = $installed['dev-package-names'] ?? [];

        $discovered = collect($installed['packages'])
            ->reject(fn ($package) => in_array($package['name'], $dev))
            ->flatMap(fn ($package) => $package['extra']['laravel']['providers'] ?? [])
            ->all();

        return array_values(array_unique([...parent::getPackageProviders($app), ...$discovered]));
    }

    protected function defineEnvironment($app) : void
    {
        parent::defineEnvironment($app);

        self::$diskCalls = 0;

        $app['config']->set('filesystems.default', 'unreachable');
        $app['config']->set('filesystems.disks.unreachable', ['driver' => 'unreachable']);

        $app->afterResolving('filesystem', function (FilesystemManager $manager) {
            $manager->extend('unreachable', fn () => new class {
                public function __call($method, $arguments)
                {
                    BootDefaultDiskTest::$diskCalls++;

                    throw new RuntimeException("Default disk touched: {$method}()");
                }
            });
        });
    }

    #[Test]
    public function the_app_boots_and_restores_xml_without_touching_the_default_disk() : void
    {
        $flat = UBL::restore(file_get_contents(__DIR__.'/../Fixtures/Xml/invoice.xml'));

        $this->assertSame('INV-XML-1', $flat['number']);
        $this->assertSame(0, self::$diskCalls);
    }
}
