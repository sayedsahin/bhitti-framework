<?php

declare(strict_types=1);

namespace Bhitti\Console\Commands;

use Bhitti\Console\CommandInterface;
use Bhitti\Console\Input;
use Bhitti\Console\Output;
use InvalidArgumentException;
use RuntimeException;

final class CreateSeederCommand implements CommandInterface
{
    public function handle(Input $input, Output $output): int
    {
        $name = trim((string) $input->argument(0, ''));

        if ($name === '') {
            throw new InvalidArgumentException('Usage: php run create:seeder name');
        }

        $name = preg_replace('/\.seeder\.php$/i', '', $name) ?? $name;

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid seeder name.');
        }

        $path = ROOT_PATH . '/database/seeders';
        $filename = $name . '.seeder.php';
        $file = $path . '/' . $filename;

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create seeders directory.');
        }

        if (is_file($file)) {
            throw new InvalidArgumentException("Seeder [{$filename}] already exists.");
        }

        $template = <<<'PHP'
<?php

declare(strict_types=1);

return static function (): void {
    //
};
PHP;

        if (file_put_contents($file, $template . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Unable to create seeder [{$filename}].");
        }

        $registered = $this->register($path, $filename);

        $output->success("Created seeder: {$filename}");

        if ($registered) {
            $output->line('Registered in database.seeder.php');
        } else {
            $output->line('Registry entry already exists; left unchanged.');
        }

        return 0;
    }

    private function register(string $path, string $filename): bool
    {
        $registry = $path . '/database.seeder.php';

        if (!is_file($registry)) {
            $content = "<?php\n\nreturn [\n    '{$filename}',\n];\n";

            if (file_put_contents($registry, $content, LOCK_EX) === false) {
                throw new RuntimeException('Unable to create database.seeder.php.');
            }

            return true;
        }

        $content = file_get_contents($registry);

        if ($content === false) {
            throw new RuntimeException('Unable to read database.seeder.php.');
        }

        // Preserve active or commented entries exactly as the developer left them.
        if (preg_match('/[\'\"]' . preg_quote($filename, '/') . '[\'\"]/', $content)) {
            return false;
        }

        $position = strrpos($content, '];');

        if ($position === false) {
            throw new RuntimeException('Invalid database.seeder.php registry.');
        }

        $content = substr($content, 0, $position)
            . "    '{$filename}'," . PHP_EOL
            . substr($content, $position);

        if (file_put_contents($registry, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to update database.seeder.php.');
        }

        return true;
    }
}
