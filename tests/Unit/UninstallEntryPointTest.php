<?php

declare(strict_types=1);

namespace FundKit\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WordPress includes uninstall.php by itself: the plugin is not loaded, no
 * hook of ours has run, and the working directory is wherever wp-admin left
 * it. Whatever the file requires is the whole of what the eraser gets, and one
 * require short of it the first query fatals on the screen the site owner
 * clicked Delete from, with the data they asked to remove still there.
 *
 * Run out of process, because this suite's own bootstrap loads both
 * autoloaders and so can never see the condition production has. The child
 * changes directory first for the same reason: the development shim that
 * happens to pull in the prefixed autoloader is keyed on the working
 * directory, and is not installed in a `--no-dev` build at all.
 */
final class UninstallEntryPointTest extends TestCase
{
    private string $probe = '';

    protected function tearDown(): void
    {
        if ($this->probe !== '' && file_exists($this->probe)) {
            unlink($this->probe);
        }
        $this->probe = '';

        parent::tearDown();
    }

    /**
     * The opt-in is left unset, so the file returns before it deletes
     * anything. What is asserted is what it leaves behind it: a runtime where
     * the eraser's own work can be done. coreTables() is the first thing
     * erase() reaches, and it instantiates every model, which is where a
     * missing prefixed base class shows up.
     */
    public function test_including_uninstall_leaves_the_eraser_able_to_run(): void
    {
        $result = $this->runProbe(<<<'PHP'
        $eraser = new FundKit\Foundation\Uninstall\DataEraser();
        echo 'TABLES=' . count($eraser->coreTables());
        PHP);

        $this->assertStringNotContainsString('Fatal error', $result);
        $this->assertMatchesRegularExpression('/TABLES=([1-9]\d*)$/', $result);
    }

    /** The models are the erase: nothing it removes is reachable without them. */
    public function test_the_models_load_after_the_file_has_bootstrapped(): void
    {
        $result = $this->runProbe(<<<'PHP'
        echo class_exists('FundKit\Campaigns\Campaign') ? 'CAMPAIGN=yes' : 'CAMPAIGN=no';
        PHP);

        $this->assertSame('CAMPAIGN=yes', $result);
    }

    /**
     * A site that never asked keeps everything, which is the branch every
     * ordinary Delete takes: the deactivation before it spends the opt-in.
     */
    public function test_an_uninstall_nobody_asked_for_erases_nothing(): void
    {
        $result = $this->runProbe(<<<'PHP'
        echo 'ERASED=' . ($GLOBALS['erased'] ? 'yes' : 'no');
        PHP);

        $this->assertSame('ERASED=no', $result);
    }

    /**
     * A site the erase throws on: an add-on listening on fundkit.uninstall, or
     * simply a site the plugin was never active on and whose tables are not
     * there to read. Stopping at it left every site after it holding its
     * donors while the owner was told the data was gone, which is the same
     * harm in a smaller shape.
     */
    public function test_one_site_failing_does_not_end_the_wipe_for_the_rest(): void
    {
        $result = $this->runProbe('', [
            'multisite' => true,
            'sites'     => [1, 2, 3],
        ]);

        $this->assertStringContainsString('SWITCHED=1,2,3', $result, 'the wipe stopped at the site that failed');
        $this->assertStringContainsString('RESTORED=3', $result, 'and every site is switched back out of');
    }

    /**
     * The child stubs the handful of WordPress functions uninstall.php reads
     * on the way to its early return, requires the real file, then runs the
     * assertion body.
     *
     * @param array{multisite?:bool, sites?:list<int>} $opts
     */
    private function runProbe(string $body, array $opts = []): string
    {
        $root      = dirname(__DIR__, 2);
        $multisite = ! empty($opts['multisite']);
        $sites     = $opts['sites'] ?? [];

        // Only the multisite probe claims the opt-in, so the ordinary ones keep
        // taking the early return that erases nothing.
        $optIn = $multisite ? 'time()' : '$default';

        $script = "<?php\n"
            . "chdir(sys_get_temp_dir());\n"
            . "define('ABSPATH', '/dev/null/');\n"
            . "define('WP_UNINSTALL_PLUGIN', 'fundkit/fundkit.php');\n"
            . "\$GLOBALS['erased'] = false;\n"
            . "\$GLOBALS['switched'] = [];\n"
            . "\$GLOBALS['restored'] = 0;\n"
            . "function get_option(\$name, \$default = false) {\n"
            . "    return \$name === 'fundkit_delete_data' ? {$optIn} : \$default;\n"
            . "}\n"
            . "function delete_option(\$name) { return true; }\n"
            . "function is_multisite() { \$GLOBALS['erased'] = true; return " . var_export($multisite, true) . "; }\n"
            . 'function get_sites($a = []) { return ' . var_export($sites, true) . "; }\n"
            . "function get_current_network_id() { return 1; }\n"
            . "function switch_to_blog(\$id) { \$GLOBALS['switched'][] = \$id; return true; }\n"
            . "function restore_current_blog() { \$GLOBALS['restored']++; return true; }\n"
            // Only the multisite probe reports, so the others keep asserting on
            // exactly what their own body echoes.
            . ($multisite
                ? "register_shutdown_function(static function (): void {\n"
                    . "    echo \"\\nSWITCHED=\" . implode(',', \$GLOBALS['switched']);\n"
                    . "    echo \"\\nRESTORED=\" . \$GLOBALS['restored'];\n"
                    . "});\n"
                : '')
            . 'require ' . var_export($root . '/uninstall.php', true) . ";\n"
            . $body . "\n";

        $this->probe = sys_get_temp_dir() . '/fundkit-uninstall-probe-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($this->probe, $script);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->probe) . ' 2>&1', $lines);

        return trim(implode("\n", $lines));
    }
}
