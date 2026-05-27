<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (!class_exists(\FastVolt\Helper\Markdown::class)) {
    eval(<<<'PHP'
namespace FastVolt\Helper;

final class Markdown
{
    private string $content = '';

    public static function new(): self
    {
        return new self();
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getHtml(): string
    {
        $html = htmlspecialchars($this->content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $html);

        return (string) $html;
    }
}
PHP);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Assertion failed for %s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed for ' . $label);
    }
}

function assertNull(mixed $actual, string $label): void
{
    if ($actual !== null) {
        throw new RuntimeException(sprintf(
            'Assertion failed for %s: expected null, got %s',
            $label,
            var_export($actual, true)
        ));
    }
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}

/**
 * @param array<string, mixed> $config
 */
function createTestConfig(array $config): string
{
    $path = '/home/web/tmp/transport-telegram-test-config-' . bin2hex(random_bytes(8)) . '.php';
    $code = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($path, $code) === false) {
        throw new RuntimeException('Failed to write test config');
    }

    return $path;
}

function removeTestConfig(string $path): void
{
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Failed to remove test config');
    }
}
