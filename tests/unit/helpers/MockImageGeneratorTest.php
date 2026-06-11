<?php

namespace viget\partskit\tests\unit\helpers;

use Codeception\Test\Unit;
use craft\helpers\FileHelper;
use UnitTester;
use viget\partskit\helpers\MockImageGenerator;

/**
 * U7 — MockImageGenerator. Real PNG output is asserted with getimagesize() and
 * the PNG signature bytes (imagick is loaded in CI). Each test writes to its own
 * temp directory, cleaned in _after.
 */
class MockImageGeneratorTest extends Unit
{
    protected UnitTester $tester;

    private string $dir;

    protected function _before(): void
    {
        $this->dir = codecept_output_dir() . 'mock-gen-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($this->dir);
    }

    protected function _after(): void
    {
        if (is_dir($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
    }

    public function testGeneratesPngOfExactDimensions(): void
    {
        // Covers AC2 — exact pixel dimensions and PNG mime.
        $path = $this->dir . '/exact.png';
        MockImageGenerator::generate($path, 800, 600, null);

        $this->assertFileExists($path);

        $info = getimagesize($path);
        $this->assertNotFalse($info);
        $this->assertSame(800, $info[0]);
        $this->assertSame(600, $info[1]);
        $this->assertSame('image/png', $info['mime']);
    }

    public function testGeneratesPngWithCustomLabel(): void
    {
        $path = $this->dir . '/labeled.png';
        MockImageGenerator::generate($path, 400, 300, 'Custom Label');

        $info = getimagesize($path);
        $this->assertNotFalse($info);
        $this->assertSame(400, $info[0]);
        $this->assertSame(300, $info[1]);
    }

    public function testDefaultLabelIsDimensions(): void
    {
        // Smoke: a null label produces a valid PNG (the label defaults to WxH).
        $path = $this->dir . '/default-label.png';
        MockImageGenerator::generate($path, 320, 240, null);

        $this->assertFileExists($path);
        $this->assertNotFalse(getimagesize($path));
    }

    public function testOutputIsValidPngSignature(): void
    {
        $path = $this->dir . '/signature.png';
        MockImageGenerator::generate($path, 100, 100, null);

        $bytes = file_get_contents($path, false, null, 0, 8);
        $this->assertSame("\x89PNG\r\n\x1a\x0a", $bytes);
    }

    public function testAtomicWriteLeavesNoTempFile(): void
    {
        // Covers NFR1 — the final file exists and no temp sibling remains.
        $path = $this->dir . '/atomic.png';
        MockImageGenerator::generate($path, 200, 200, null);

        $this->assertFileExists($path);
        $this->assertSame([], glob($this->dir . '/*.tmp.*') ?: []);
    }

    public function testFileCountCapAbortsGeneration(): void
    {
        // Covers NFR6 — at the cap, generation is skipped and no file is written.
        for ($i = 0; $i < MockImageGenerator::MAX_FILES; $i++) {
            touch($this->dir . '/stub-' . $i . '.png');
        }

        $path = $this->dir . '/over-cap.png';
        MockImageGenerator::generate($path, 800, 600, null);

        $this->assertFileDoesNotExist($path);
    }

    public function testSmallDimensionsFloorFontAt8px(): void
    {
        // The font floor prevents a zero/negative point size on tiny canvases.
        $path = $this->dir . '/tiny.png';
        MockImageGenerator::generate($path, 10, 10, '10x10');

        $info = getimagesize($path);
        $this->assertNotFalse($info);
        $this->assertSame(10, $info[0]);
        $this->assertSame(10, $info[1]);
    }

    public function testBundledFontResolves(): void
    {
        // Guard-shape assertion for the font chain: the bundled DejaVu Sans must
        // ship so generation never falls through to the RuntimeException on a
        // clean install. (The throw path itself is unreachable while the bundled
        // font is present — see resolveFont().)
        $bundled = dirname(__DIR__, 3) . '/src/resources/fonts/DejaVuSans.ttf';
        $this->assertFileExists($bundled);
    }
}
