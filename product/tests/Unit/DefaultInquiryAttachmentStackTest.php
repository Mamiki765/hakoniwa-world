<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DefaultInquiryAttachmentStackTest extends TestCase
{
    public function test_default_compose_persists_inquiry_attachments(): void
    {
        $productRoot = dirname(__DIR__, 2);
        $repositoryRoot = dirname($productRoot);
        if (! is_file($repositoryRoot.'/compose.yml')) {
            $this->markTestSkipped('The repository-level Compose file is outside this product-only runtime image.');
        }

        $compose = file_get_contents($repositoryRoot.'/compose.yml');
        $this->assertIsString($compose);
        $compose = str_replace("\r\n", "\n", $compose);

        foreach ([
            'hakoniwa_inquiry_attachments' => 'HAKONIWA_INQUIRY_ATTACHMENT_PATH',
            'hakoniwa_secretary_images' => 'HAKONIWA_SECRETARY_IMAGE_PATH',
        ] as $volume => $pathVariable) {
            $this->assertSame(
                1,
                preg_match('/^\s*-\s*'.$volume.':(?<path>\S+?)\s*$/m', $compose, $mount),
                "{$volume} must be mounted as a named volume.",
            );
            $this->assertMatchesRegularExpression('/^\s+'.$volume.':\s*\n\s+name:\s*'.$volume.'\s*$/m', $compose);
            $this->assertSame(
                1,
                preg_match('/^\s*'.$pathVariable.':\s*\$\{'.$pathVariable.':-(?<path>[^}]+)\}/m', $compose, $environment),
                "{$pathVariable} must default to a configured path.",
            );
            $this->assertSame($mount['path'], $environment['path'], "{$pathVariable} must point at the persisted volume.");
        }
    }

    public function test_apache_serves_public_attachment_paths_without_indexing(): void
    {
        $productRoot = dirname(__DIR__, 2);
        $apache = file_get_contents($productRoot.'/docker/apache-vhost.conf');
        $this->assertIsString($apache);

        foreach ([
            'hakoniwa-inquiries' => '/Cache-Control\s+"[^"]*\bprivate\b[^"]*\bno-store\b/',
            'hakoniwa-secretaries' => '/Cache-Control\s+"[^"]*\bpublic\b/',
        ] as $directory => $cachePolicy) {
            $root = '/srv/bot-assets/'.$directory;
            $this->assertMatchesRegularExpression(
                '#Alias\s+"?/'.$directory.'/"?\s+"?'.$root.'/"?#',
                $apache,
            );
            $this->assertSame(
                1,
                preg_match('#<Directory "'.$root.'">(?<directives>.*?)</Directory>#s', $apache, $matches),
            );
            $directives = $matches['directives'];
            $this->assertMatchesRegularExpression('/^\s*AllowOverride\s+None\b/m', $directives);
            foreach (['-Indexes', '-ExecCGI', '-Includes'] as $option) {
                $this->assertMatchesRegularExpression('/^\s*Options\b[^\n]*\s'.preg_quote($option, '/').'\b/m', $directives);
            }
            $this->assertMatchesRegularExpression('/^\s*SetHandler\s+none\b/m', $directives);
            $this->assertMatchesRegularExpression('/X-Content-Type-Options\s+"nosniff"/', $directives);
            $this->assertMatchesRegularExpression($cachePolicy, $directives);
        }
    }

    public function test_development_compose_uses_test_only_environment_and_safe_source_mounts(): void
    {
        $productRoot = dirname(__DIR__, 2);
        $repositoryRoot = dirname($productRoot);
        $developmentComposePath = $repositoryRoot.'/compose.development.yml';
        if (! is_file($developmentComposePath)) {
            $this->markTestSkipped('The repository-level development Compose file is outside this product-only runtime image.');
        }

        $compose = file_get_contents($developmentComposePath);

        $this->assertIsString($compose);
        $this->assertMatchesRegularExpression('/^\s*APP_ENV:\s*testing\s*$/m', $compose);
        $this->assertMatchesRegularExpression('/^\s*DB_DATABASE:\s*hakoniwa_test\s*$/m', $compose);
        $this->assertDoesNotMatchRegularExpression('#:/var/www/html/?(:|\s|$)#m', $compose);
        foreach (['vendor', 'storage', 'bootstrap/cache', 'public/build'] as $generated) {
            $this->assertStringNotContainsString(':/var/www/html/'.$generated, $compose);
        }
    }
}
