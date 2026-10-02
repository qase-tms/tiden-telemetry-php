<?php

declare(strict_types=1);

namespace Tiden\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\Client;

final class VersionTest extends TestCase
{
    /** @return iterable<string,array{?string,?string}> */
    public static function prettyVersions(): iterable
    {
        yield 'tag with v' => ['v0.2.0', '0.2.0'];
        yield 'tag without v' => ['0.2.0', '0.2.0'];
        yield 'pre-release tag' => ['v0.3.0-RC1', '0.3.0-RC1'];
        yield 'v not followed by a digit is kept' => ['vendor-1', 'vendor-1'];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'dev branch' => ['dev-main', null];
        yield 'branch alias' => ['0.2.x-dev', null];
        yield 'root package without a version' => ['1.0.0+no-version-set', null];
    }

    #[DataProvider('prettyVersions')]
    public function test_normalize_installed_version(?string $pretty, ?string $expected): void
    {
        $this->assertSame($expected, Client::normalizeInstalledVersion($pretty));
    }

    public function test_version_uses_composer_or_falls_back_to_the_constant(): void
    {
        $this->assertSame('0.2.0', Client::VERSION);

        $expected = Client::VERSION;
        if (InstalledVersions::isInstalled('tiden/telemetry-php')) {
            $expected = Client::normalizeInstalledVersion(InstalledVersions::getPrettyVersion('tiden/telemetry-php'))
                ?? Client::VERSION;
        }

        $this->assertSame($expected, Client::version());
        $this->assertSame(Client::version(), Client::version(), 'cached value is stable');
    }
}
