<?php

namespace Bschmitt\Amqp\Test\Unit;

use Bschmitt\Amqp\Support\LaravelCloud;
use PHPUnit\Framework\TestCase;

class LaravelCloudTest extends TestCase
{
    /** @var array<string, string|false> */
    private $originalEnv = [];

    /** @var array<int, string> */
    private $cloudKeys = [
        'LARAVEL_CLOUD',
        'LARAVEL_CLOUD_DEPLOYMENT_ID',
        'LARAVEL_CLOUD_REGION',
        'CLOUD_DEPLOYMENT_ID',
        'FLY_APP_NAME',
        'FLY_REGION',
        'FLY_ALLOC_ID',
        'RENDER_SERVICE_ID',
        'RENDER_INSTANCE_ID',
        'VAPOR_SSM_PATH',
        'FORGE_SITE_NAME',
        'FORGE_REGION',
        'AWS_REGION',
        'CLOUD_REGION',
        'AMQP_URL',
        'CLOUDAMQP_URL',
        'RABBITMQ_URL',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->cloudKeys as $key) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cloudKeys as $key) {
            $value = $this->originalEnv[$key] ?? false;
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testIsHostedReturnsFalseByDefault(): void
    {
        $this->assertFalse(LaravelCloud::isHosted());
        $this->assertNull(LaravelCloud::region());
        $this->assertNull(LaravelCloud::deploymentId());
        $this->assertNull(LaravelCloud::dsn());
    }

    public function testDetectsLaravelCloud(): void
    {
        putenv('LARAVEL_CLOUD=1');
        putenv('LARAVEL_CLOUD_DEPLOYMENT_ID=dpl-123');
        putenv('LARAVEL_CLOUD_REGION=us-east-1');

        $this->assertTrue(LaravelCloud::isHosted());
        $this->assertSame('us-east-1', LaravelCloud::region());
        $this->assertSame('dpl-123', LaravelCloud::deploymentId());

        $summary = LaravelCloud::summary();
        $this->assertTrue($summary['hosted']);
        $this->assertSame('us-east-1', $summary['region']);
    }

    public function testDetectsFlyIo(): void
    {
        putenv('FLY_APP_NAME=my-app');
        putenv('FLY_REGION=iad');
        putenv('FLY_ALLOC_ID=alloc-1');

        $this->assertTrue(LaravelCloud::isHosted());
        $this->assertSame('iad', LaravelCloud::region());
        $this->assertSame('alloc-1', LaravelCloud::deploymentId());
    }

    public function testParseDsnWithCredentials(): void
    {
        $parsed = LaravelCloud::parseDsn('amqp://app:secret@rabbit.example.com:5672/prod');
        $this->assertSame('rabbit.example.com', $parsed['host']);
        $this->assertSame(5672, $parsed['port']);
        $this->assertSame('app', $parsed['username']);
        $this->assertSame('secret', $parsed['password']);
        $this->assertSame('prod', $parsed['vhost']);
    }

    public function testParseDsnHandlesTlsAndUrlEncoding(): void
    {
        $parsed = LaravelCloud::parseDsn('amqps://user%40co:p%2Fass@host.example.com/%2Fmain');

        $this->assertSame('host.example.com', $parsed['host']);
        $this->assertSame(5671, $parsed['port']);
        $this->assertSame('user@co', $parsed['username']);
        $this->assertSame('p/ass', $parsed['password']);
        $this->assertSame('/main', $parsed['vhost']);
        $this->assertArrayHasKey('ssl_options', $parsed);
    }

    public function testParseDsnReturnsEmptyArrayOnInvalidInput(): void
    {
        $this->assertSame([], LaravelCloud::parseDsn('http://not-amqp'));
        $this->assertSame([], LaravelCloud::parseDsn('not a url'));
    }

    public function testDsnLookupPrefersAmqpUrl(): void
    {
        putenv('AMQP_URL=amqp://a@b/c');
        putenv('CLOUDAMQP_URL=amqp://x@y/z');

        $this->assertSame('amqp://a@b/c', LaravelCloud::dsn());
    }

    public function testMergePropertiesPrefersExplicitConfigButFillsBlanks(): void
    {
        $existing = ['host' => 'localhost', 'username' => '', 'vhost' => '/custom', 'port' => 5672];
        $cloud = ['host' => 'rabbit.cloud', 'username' => 'cloud-user', 'password' => 'cloud-pass'];

        $merged = LaravelCloud::mergeProperties($existing, $cloud);

        $this->assertSame('rabbit.cloud', $merged['host']);    // default localhost -> overridden
        $this->assertSame('cloud-user', $merged['username']);  // blank -> overridden
        $this->assertSame('cloud-pass', $merged['password']);  // missing -> added
        $this->assertSame('/custom', $merged['vhost']);        // explicit -> preserved
        $this->assertSame(5672, $merged['port']);              // explicit -> preserved
    }
}
