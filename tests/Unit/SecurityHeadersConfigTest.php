<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Phase K2-E (residual security hardening).
//
// docker/nginx/default.conf and .htaccess are web-server configuration,
// not PHP - PHPUnit's own HTTP test harness (tests/Http/HttpServerTestCase)
// deliberately drives PHP's built-in `php -S` server rather than a real
// Nginx or Apache (see that file's own header comment), so neither
// add_header nor mod_headers ever actually runs during the automated
// suite. Spinning up a real web server as part of this project's
// standard PHPUnit run would introduce exactly the kind of
// environment-specific fragility this batch was told to avoid - not
// every environment that runs `vendor/bin/phpunit` has Nginx/Apache
// installed or permission to bind a port.
//
// This is therefore a STATIC check on the configuration text itself: it
// pins that the three approved headers are present, with the modifiers
// that make them apply consistently (`always` / mod_headers), that the
// K1 deny rules were not touched, and that CSP/HSTS/Permissions-Policy
// were not added - matching the explicit scope boundary for this batch.
// The actual live HTTP response was verified manually (see the K2-E
// implementation report's Manual QA section) against real Nginx and
// Apache instances, which is also how K1's original web-boundary rules
// were verified.
final class SecurityHeadersConfigTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    public function testNginxConfigSetsTheThreeApprovedHeaders(): void
    {
        $conf = file_get_contents(self::REPO_ROOT . '/docker/nginx/default.conf');

        $this->assertMatchesRegularExpression(
            '/add_header\s+X-Frame-Options\s+"SAMEORIGIN"\s+always;/',
            $conf
        );
        $this->assertMatchesRegularExpression(
            '/add_header\s+X-Content-Type-Options\s+"nosniff"\s+always;/',
            $conf
        );
        $this->assertMatchesRegularExpression(
            '/add_header\s+Referrer-Policy\s+"same-origin"\s+always;/',
            $conf
        );
    }

    public function testHtaccessSetsTheThreeApprovedHeaders(): void
    {
        $htaccess = file_get_contents(self::REPO_ROOT . '/.htaccess');

        $this->assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>.*?Header always set X-Frame-Options "SAMEORIGIN".*?<\/IfModule>/s',
            $htaccess
        );
        $this->assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $htaccess);
        $this->assertStringContainsString('Header always set Referrer-Policy "same-origin"', $htaccess);
    }

    // Guards against exactly the failure mode a security-headers change
    // is prone to: adding CSP/HSTS "while we're in here" without the
    // deliberate, separate decision ADR-008 says each one needs.
    public function testNeitherConfigFileAddsCspOrHstsOrPermissionsPolicy(): void
    {
        // Checked against the ACTIVE directive lines only, not the file
        // text as a whole - both files carry an explanatory comment
        // naming CSP/HSTS to say why they were deliberately left out,
        // and that comment mentioning the header by name is not the
        // same thing as the header being emitted.
        foreach ([
            self::REPO_ROOT . '/docker/nginx/default.conf' => '/^\s*add_header\s+/',
            self::REPO_ROOT . '/.htaccess' => '/^\s*Header\s+/',
        ] as $path => $directivePattern) {
            $activeLines = array_filter(
                file($path, FILE_IGNORE_NEW_LINES),
                fn (string $line): bool => preg_match($directivePattern, $line) === 1
            );
            $activeText = implode("\n", $activeLines);

            $this->assertStringNotContainsStringIgnoringCase('Content-Security-Policy', $activeText, "$path must not SET a CSP header in K2-E");
            $this->assertStringNotContainsStringIgnoringCase('Strict-Transport-Security', $activeText, "$path must not SET an HSTS header in K2-E");
            $this->assertStringNotContainsStringIgnoringCase('Permissions-Policy', $activeText, "$path must not SET a Permissions-Policy header in K2-E");
        }
    }

    // Regression guard: the K1 deny rules must survive this batch
    // untouched. Deliberately re-checks the same rules K1's own tests
    // covered manually, not because K2-E is expected to break them, but
    // because it is the two files K2-E happens to also edit.
    public function testK1DenyRulesAreStillPresentInBothConfigFiles(): void
    {
        $nginx = file_get_contents(self::REPO_ROOT . '/docker/nginx/default.conf');
        foreach (['/database/', '/tests/', '/docs/', '/docker/', '/vendor/'] as $path) {
            $this->assertStringContainsString($path, $nginx, "nginx config must still deny $path");
        }
        $this->assertStringContainsString('location ~ /\.', $nginx, 'nginx must still deny dotfiles');

        $htaccess = file_get_contents(self::REPO_ROOT . '/.htaccess');
        $this->assertStringContainsString('(database|tests|docs|docker|vendor)', $htaccess);
        $this->assertStringContainsString('RewriteRule (^|/)\.', $htaccess);
    }
}
