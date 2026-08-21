<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateSecurityTest extends TestCase
{
    #[DataProvider('viewFiles')]
    public function testViewsDoNotContainInlineHandlersStylesOrJavascriptUrls(string $path): void
    {
        $contents = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $contents, $path);
        $this->assertDoesNotMatchRegularExpression('/\sonclick\s*=/i', $contents, $path);
        $this->assertDoesNotMatchRegularExpression('/\sonsubmit\s*=/i', $contents, $path);
        $this->assertDoesNotMatchRegularExpression('/javascript\s*:/i', $contents, $path);
        $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bsrc\s*=)[^>]*>/i', $contents, $path);
    }

    public function testInstitutionalLogoAndStaticFaviconsArePresent(): void
    {
        $logo = ROOT_PATH . '/public/assets/image/logo_escola.png';
        $layout = (string) file_get_contents(ROOT_PATH . '/src/Views/layouts/app.php');
        $login = (string) file_get_contents(ROOT_PATH . '/src/Views/login.php');
        $error = (string) file_get_contents(ROOT_PATH . '/src/Views/errors/standalone.php');

        $this->assertFileExists($logo);
        $this->assertSame("\x89PNG\r\n\x1a\n", (string) file_get_contents($logo, false, null, 0, 8));
        $this->assertStringContainsString("url('assets/image/logo_escola.png')", $layout);
        $this->assertStringContainsString("url('assets/image/logo_escola.png')", $login);
        $this->assertStringContainsString('rel="icon"', $layout);
        $this->assertStringContainsString('rel="icon"', $login);
        $this->assertStringContainsString('rel="icon"', $error);
    }

    public function testLogoutRemainsPostWithCsrf(): void
    {
        $layout = (string) file_get_contents(ROOT_PATH . '/src/Views/layouts/app.php');

        $this->assertStringContainsString('method="post"', $layout);
        $this->assertStringContainsString("url('login/sair')", $layout);
        $this->assertStringContainsString('name="_csrf_token"', $layout);
    }

    /** @return iterable<string,array{string}> */
    public static function viewFiles(): iterable
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            ROOT_PATH . '/src/Views',
            \FilesystemIterator::SKIP_DOTS
        ));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname() => [$file->getPathname()];
            }
        }
    }
}
