<?php

namespace Tests\Feature;

use App\Services\AssetManifestResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

class TileAssetTest extends TestCase
{
    private string $assetDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assetDirectory = storage_path('framework/testing/assets-'.Str::uuid());
        mkdir($this->assetDirectory, 0777, true);
        config([
            'hakoniwa.assets.path' => $this->assetDirectory,
            'hakoniwa.assets.base_url' => '/assets/hakoniwa-tiles',
            'hakoniwa.assets.allowed_extensions' => ['gif', 'png', 'webp'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->assetDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->assetDirectory)) {
            rmdir($this->assetDirectory);
        }
        parent::tearDown();
    }

    public function test_manifest_only_serves_valid_square_images_and_resolves_optional_layers(): void
    {
        $this->writeGif('land0.gif');
        $this->writeGif('land6.gif');
        $this->writeGif('capital.gif');
        $resolver = app(AssetManifestResolver::class);
        $layers = $resolver->resolveLayers('tile.sea', '海', ['tile.forest']);

        $this->assertTrue($layers['completed']['available']);
        $this->assertSame('tile.forest', $layers['overlays'][0]['key']);
        $this->assertTrue($layers['overlays'][0]['available']);
        $this->assertStringContainsString('/capital.gif?v=', (string) $resolver->resolve('tile.capital', '首都')['url']);
        $this->assertNull($resolver->pathForFilename('../land0.gif'));
        $this->assertFalse($resolver->resolve('unregistered.asset', '?')['available']);

        config(['hakoniwa.assets.allowed_extensions' => ['png']]);
        $this->assertFalse($resolver->resolve('tile.sea', '海')['available']);
    }

    public function test_missing_non_square_and_corrupt_images_fall_back_instead_of_failing(): void
    {
        $resolver = app(AssetManifestResolver::class);
        $this->assertFalse($resolver->resolve('tile.sea', '海')['available']);

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        $this->assertIsString($gif);
        $gif[6] = "\x02";
        file_put_contents($this->assetDirectory.DIRECTORY_SEPARATOR.'land0.gif', $gif);
        $this->assertFalse($resolver->resolve('tile.sea', '海')['available']);

        file_put_contents($this->assetDirectory.DIRECTORY_SEPARATOR.'land0.gif', 'not-an-image');
        $this->assertFalse($resolver->resolve('tile.sea', '海')['available']);
    }

    public function test_settlement_and_missile_scar_tiles_use_the_confirmed_original_mappings(): void
    {
        foreach (['land1.gif', 'land3.gif', 'land4.gif', 'land5.gif', 'land13.gif'] as $filename) {
            $this->writeGif($filename);
        }

        $resolver = app(AssetManifestResolver::class);

        $this->assertStringContainsString('/land1.gif?v=', (string) $resolver->resolve('tile.wasteland', '荒地')['url']);
        $this->assertStringContainsString('/land3.gif?v=', (string) $resolver->resolve('tile.village', '村')['url']);
        $this->assertStringContainsString('/land4.gif?v=', (string) $resolver->resolve('tile.town', '町')['url']);
        $this->assertStringContainsString('/land5.gif?v=', (string) $resolver->resolve('tile.city', '都市')['url']);
        $this->assertStringContainsString('/land13.gif?v=', (string) $resolver->resolve('tile.scorched', '焼け跡')['url']);
    }

    public function test_replacing_same_filename_changes_version_url_and_route_cache_headers(): void
    {
        $path = $this->writeGif('land0.gif');
        $resolver = app(AssetManifestResolver::class);
        $before = $resolver->resolve('tile.sea', '海')['url'];

        file_put_contents($path, "\0", FILE_APPEND);
        touch($path, time() + 2);
        clearstatcache(true, $path);
        $after = $resolver->resolve('tile.sea', '海')['url'];

        $this->assertNotSame($before, $after);
        $this->assertStringContainsString('?v=', (string) $after);
        $response = $this->get('/assets/hakoniwa-tiles/land0.gif')->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
        $cacheControl = explode(', ', (string) $response->headers->get('Cache-Control'));
        sort($cacheControl);
        $this->assertSame(['immutable', 'max-age=31536000', 'public'], $cacheControl);
        $this->get('/assets/hakoniwa-tiles/not-listed.gif')->assertNotFound();
    }

    private function writeGif(string $filename): string
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        $this->assertIsString($gif);
        $path = $this->assetDirectory.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $gif);

        return $path;
    }
}
