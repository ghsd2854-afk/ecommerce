<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Architecture test: proves the modules respect each other's boundaries.
 *
 * Rules:
 *  1. A module may use another module ONLY through its public API:
 *     Modules\<Other>\Contracts\..., Modules\<Other>\Events\..., Modules\<Other>\Exceptions\...
 *     Never its Models, Services, Http, Database...
 *  2. A module never reads another module's tables directly
 *     (no DB::table('x'), joins or exists:/unique: validation rules on them).
 *     Migrations are excluded: they only describe the module's own schema history.
 */
class ModuleBoundariesTest extends TestCase
{
    private const PUBLIC_PARTS = ['Contracts', 'Events', 'Exceptions'];

    /** Which module owns which table. */
    private const TABLES = [
        'users' => 'Users',
        'otp_codes' => 'Users',
        'products' => 'Products',
        'orders' => 'Orders',
        'order_items' => 'Orders',
        'user_notifications' => 'Notifications',
    ];

    public function test_modules_only_use_each_others_public_api(): void
    {
        $violations = [];

        foreach (self::moduleFiles() as [$module, $file, $code]) {
            preg_match_all('/^use\s+Modules\\\\(\w+)\\\\(\w+)/m', $code, $matches, PREG_SET_ORDER);

            foreach ($matches as [$line, $other, $part]) {
                if ($other !== $module && ! in_array($part, self::PUBLIC_PARTS, true)) {
                    $violations[] = "$file uses Modules\\$other\\$part (not a public API of $other)";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_modules_do_not_read_other_modules_tables(): void
    {
        $violations = [];

        foreach (self::moduleFiles() as [$module, $file, $code]) {
            if (str_contains($file, '/Database/Migrations/')) {
                continue;
            }

            foreach (self::TABLES as $table => $owner) {
                if ($owner === $module) {
                    continue;
                }

                $patterns = [
                    "/DB::table\\(\\s*['\"]{$table}['\"]/",
                    "/->join\\(\\s*['\"]{$table}['\"]/",
                    "/(exists|unique):{$table}\\b/",
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $code)) {
                        $violations[] = "$file reads table '$table' owned by $owner";
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_every_module_registers_itself_with_its_own_provider(): void
    {
        $providers = file_get_contents(self::root().'/bootstrap/providers.php');

        foreach (glob(self::root().'/Modules/*', GLOB_ONLYDIR) as $dir) {
            $module = basename($dir);
            $provider = "Modules\\{$module}\\Providers\\{$module}ServiceProvider";

            $this->assertFileExists("$dir/Providers/{$module}ServiceProvider.php", "$module has no service provider");
            $this->assertStringContainsString("use $provider;", $providers, "$module provider is not registered");
        }
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> [module, relative file, code] */
    private static function moduleFiles(): array
    {
        $files = [];
        $root = self::root();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/Modules', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $module = explode('/', $relative)[1];
            $files[] = [$module, $relative, file_get_contents($file->getPathname())];
        }

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
