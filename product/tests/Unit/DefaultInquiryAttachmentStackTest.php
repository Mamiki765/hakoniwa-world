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
        $this->assertStringContainsString(
            '- hakoniwa_inquiry_attachments:/srv/bot-assets/hakoniwa-inquiries',
            $compose,
        );
        $this->assertStringContainsString(
            "hakoniwa_inquiry_attachments:\n    name: hakoniwa_inquiry_attachments",
            str_replace("\r\n", "\n", $compose),
        );
        $this->assertStringContainsString(
            'HAKONIWA_INQUIRY_ATTACHMENT_PATH: ${HAKONIWA_INQUIRY_ATTACHMENT_PATH:-/srv/bot-assets/hakoniwa-inquiries}',
            $compose,
        );
        $this->assertStringContainsString(
            'HAKONIWA_INQUIRY_ATTACHMENT_BASE_URL: ${HAKONIWA_INQUIRY_ATTACHMENT_BASE_URL:-/hakoniwa-inquiries}',
            $compose,
        );
    }

    public function test_apache_serves_public_attachment_paths_without_indexing(): void
    {
        $productRoot = dirname(__DIR__, 2);
        $apache = file_get_contents($productRoot.'/docker/apache-vhost.conf');

        $this->assertIsString($apache);
        $this->assertStringContainsString(
            'Alias "/hakoniwa-inquiries/" "/srv/bot-assets/hakoniwa-inquiries/"',
            $apache,
        );
        $matched = preg_match(
            '/<Directory "\/srv\/bot-assets\/hakoniwa-inquiries">(?<directives>.*?)<\/Directory>/s',
            $apache,
            $matches,
        );
        $this->assertSame(1, $matched);
        $directives = $matches['directives'];
        $this->assertStringContainsString('AllowOverride None', $directives);
        $this->assertStringContainsString('Options -Indexes -ExecCGI -Includes', $directives);
        $this->assertStringContainsString('SetHandler none', $directives);
        $this->assertStringContainsString('Require all granted', $directives);
        $this->assertStringContainsString('Header always set Cache-Control "private, no-store, max-age=0"', $directives);
        $this->assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $directives);
    }
}
