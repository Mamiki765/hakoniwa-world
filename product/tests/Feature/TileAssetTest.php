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
        foreach (['snow', 'peridot', 'underground'] as $directory) {
            $assetSubdirectory = $this->assetDirectory.DIRECTORY_SEPARATOR.$directory;
            foreach (glob($assetSubdirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($assetSubdirectory)) {
                rmdir($assetSubdirectory);
            }
        }
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
        foreach (['land1.gif', 'land3.gif', 'land4.gif', 'land5.gif', 'land10.gif', 'land13.gif', 'undersea-city.gif', 'port.gif'] as $filename) {
            $this->writeGif($filename);
        }

        $resolver = app(AssetManifestResolver::class);

        $this->assertStringContainsString('/land1.gif?v=', (string) $resolver->resolve('tile.wasteland', '荒地')['url']);
        $this->assertStringContainsString('/land3.gif?v=', (string) $resolver->resolve('tile.village', '村')['url']);
        $this->assertStringContainsString('/land4.gif?v=', (string) $resolver->resolve('tile.town', '町')['url']);
        $this->assertStringContainsString('/land5.gif?v=', (string) $resolver->resolve('tile.city', '都市')['url']);
        $this->assertStringContainsString('/land13.gif?v=', (string) $resolver->resolve('tile.scorched', '焼け跡')['url']);
        $this->assertStringContainsString('/land10.gif?v=', (string) $resolver->resolve('tile.defense', '防衛施設')['url']);
        $this->assertStringContainsString('/land10.gif?v=', (string) $resolver->resolve('tile.decoy', 'ハリボテ')['url']);
        $this->assertStringContainsString('/undersea-city.gif?v=', (string) $resolver->resolve('tile.undersea_city', '海底都市')['url']);
        $this->assertStringContainsString('/port.gif?v=', (string) $resolver->resolve('tile.port', '港')['url']);
    }

    public function test_themed_assets_use_only_allowlisted_external_files_and_fall_back_safely(): void
    {
        mkdir($this->assetDirectory.DIRECTORY_SEPARATOR.'snow', 0777, true);
        mkdir($this->assetDirectory.DIRECTORY_SEPARATOR.'peridot', 0777, true);
        mkdir($this->assetDirectory.DIRECTORY_SEPARATOR.'underground', 0777, true);
        config([
            'hakoniwa.assets.themes.snow' => 'snow',
            'hakoniwa.assets.themes.peridot' => 'peridot',
            'hakoniwa.assets.themes.underground' => 'underground',
        ]);
        foreach (['land0.gif', 'land1.gif', 'capital.gif', 'monument.png'] as $filename) {
            $this->writeGif($filename);
        }
        foreach (['snow/land1.gif', 'snow/monument0.gif'] as $filename) {
            $this->writeGif($filename);
        }
        foreach (['peridot/peridot.png', 'peridot/silhouette.png'] as $filename) {
            $this->writePng($filename);
        }
        $undergroundAssets = [
            'underground.soil' => 'Ug.gif',
            'underground.entrance' => 'Ug_dokan.gif',
            'underground.ladder' => 'Ug_hasigo.gif',
            'underground.road' => 'Ug_road.gif',
            'underground.city' => 'Ug_tosi.gif',
            'underground.farm' => 'Ug_farm.gif',
            'underground.factory' => 'Ug_fact.gif',
            'underground.missile_base' => 'Ug_kiti.gif',
            'underground.oil' => 'Ug_oil.gif',
        ];
        foreach ($undergroundAssets as $filename) {
            $this->writeGif('underground/'.$filename);
        }
        $resolver = app(AssetManifestResolver::class);

        $this->assertStringContainsString('/snow/land1.gif?v=', (string) $resolver
            ->resolve('tile.wasteland', '荒地', 'snow')['url']);
        $this->assertStringContainsString('/snow/monument0.gif?v=', (string) $resolver
            ->resolve('tile.monument', '記念碑', 'snow')['url']);
        $this->assertStringContainsString('/land0.gif?v=', (string) $resolver
            ->resolve('tile.sea', '海', 'snow')['url']);
        $this->assertStringContainsString('/capital.gif?v=', (string) $resolver
            ->resolve('tile.capital', '首都', 'snow')['url']);
        $this->assertNull($resolver->pathForFilename('land0.gif', 'snow'));
        $this->assertNull($resolver->pathForFilename('land1.gif', 'unknown'));
        $this->get('/assets/hakoniwa-tiles/snow/land1.gif')->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
        $this->get('/assets/hakoniwa-tiles/snow/land0.gif')->assertNotFound();
        $this->assertStringContainsString(
            '/peridot/peridot.png?v=',
            (string) $resolver->secretaryFallbackUrl('peridot'),
        );
        $this->assertStringContainsString(
            '/peridot/silhouette.png?v=',
            (string) $resolver->secretaryFallbackUrl('silhouette'),
        );
        $this->assertNull($resolver->secretaryFallbackUrl('unknown'));
        $this->assertNull($resolver->pathForFilename('unknown.png', 'peridot'));
        $this->get('/assets/hakoniwa-tiles/peridot/peridot.png')->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        foreach ($undergroundAssets as $assetKey => $filename) {
            $asset = $resolver->resolve($assetKey, '地下', 'underground');
            $this->assertTrue($asset['available'], $assetKey);
            $this->assertStringContainsString("/underground/{$filename}?v=", (string) $asset['url']);
        }
        $this->assertNotNull($resolver->pathForFilename('Ug_road.gif', 'underground'));
        $this->assertNull($resolver->pathForFilename('not-allowlisted.gif', 'underground'));
        $this->get('/assets/hakoniwa-tiles/underground/Ug_road.gif')->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
        unlink($this->assetDirectory.DIRECTORY_SEPARATOR.'underground'.DIRECTORY_SEPARATOR.'Ug_oil.gif');
        $this->assertFalse($resolver->resolve('underground.oil', '将来用', 'underground')['available']);
    }

    public function test_awards_use_only_the_allowlisted_original_prize_zero_through_ten_assets(): void
    {
        foreach (range(0, 11) as $index) {
            $this->writeGif("prize{$index}.gif");
        }
        $resolver = app(AssetManifestResolver::class);
        $keys = [
            'award.turn',
            'award.prosperity', 'award.prosperity_great', 'award.prosperity_ultimate',
            'award.peace', 'award.peace_great', 'award.peace_ultimate',
            'award.calamity', 'award.calamity_great', 'award.calamity_ultimate',
            'award.monster_turn',
        ];

        foreach ($keys as $index => $key) {
            $asset = $resolver->resolve($key, '賞');
            $this->assertTrue($asset['available'], $key);
            $this->assertStringContainsString("/prize{$index}.gif?v=", (string) $asset['url'], $key);
        }

        $this->assertNull($resolver->pathForFilename('prize11.gif'));
        $this->assertFalse($resolver->resolve('award.prize11', '未使用')['available']);

        unlink($this->assetDirectory.DIRECTORY_SEPARATOR.'prize10.gif');
        $missing = $resolver->resolve('award.monster_turn', '討伐ターン賞');
        $this->assertFalse($missing['available']);
        $this->assertSame('討伐ターン賞', $missing['fallback_label']);
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

    private function writePng(string $filename): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($png);
        $path = $this->assetDirectory.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $png);

        return $path;
    }
}
