<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle;

final class ConfigurationTest extends TestCase
{
    public function testKeepsStorageNamesVerbatim(): void
    {
        $config = $this->process(['storages' => ['user-media' => ['client' => 'c', 'bucket' => 'b']]]);

        self::assertIsArray($config['storages'] ?? null);
        self::assertArrayHasKey('user-media', $config['storages']);
    }

    public function testAcceptsAFilesystemOnlyStorage(): void
    {
        $config = $this->process(['storages' => ['archive' => ['filesystem' => 'default.storage']]]);

        self::assertIsArray($config['storages'] ?? null);
        $archive = $config['storages']['archive'] ?? null;
        self::assertIsArray($archive);
        self::assertSame('default.storage', $archive['filesystem']);
        self::assertNull($archive['client']);
    }

    /**
     * @param array<string, mixed> $storage
     */
    #[DataProvider('invalidStorages')]
    public function testRejectsIncompleteStorages(array $storage, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process(['storages' => ['media' => $storage]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidStorages(): iterable
    {
        yield 'client without bucket' => [['client' => 'c'], '"client" and "bucket" have to be configured together'];
        yield 'bucket without client' => [['bucket' => 'b', 'filesystem' => 'f'], '"client" and "bucket" have to be configured together'];
        yield 'nothing to store with' => [['public_url' => 'https://cdn.example.com'], 'needs a "client" and "bucket", a "filesystem", or both'];
    }

    public function testRejectsUploadTtlsBeyondSigV4Limits(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['defaults' => ['upload_ttl' => 604801, 'claim_ttl' => 999999]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    private function process(array $config): array
    {
        $extension = (new VadagePresignedUploaderBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
