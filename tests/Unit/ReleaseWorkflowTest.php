<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The release workflows are not run by the test suite and not run by anyone
 * until a tag is pushed, which is the worst moment to learn that a path or a
 * version string is wrong. Both defects pinned here are silent: an upload step
 * that finds no file warns and passes, and an SVN tag under the wrong name is
 * published successfully and then never resolved by the directory.
 *
 * Read as text rather than parsed as YAML: there is no YAML parser in the unit
 * bootstrap, and the questions are about two literal values.
 */
final class ReleaseWorkflowTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function workflow(string $name): string
    {
        $path = $this->root() . '/.github/workflows/' . $name;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The name the packager gives the zip, from the same header it reads. */
    private function zipName(): string
    {
        $matched = preg_match(
            '/^\s*\*\s*Text Domain:\s*(\S+)\s*$/m',
            (string) file_get_contents($this->root() . '/dono.php'),
            $m
        );
        $this->assertSame(1, $matched, 'dono.php has no Text Domain, so the zip has no name.');

        return $m[1] . '.zip';
    }

    /**
     * WordPress.org resolves Stable tag to tags/<that value>. The git tag
     * carries a leading v and Stable tag does not, so publishing the raw tag
     * name creates tags/v1.0.0 while the directory looks for tags/1.0.0, finds
     * nothing, and serves mutable trunk as the release instead.
     */
    public function test_the_svn_tag_the_deploy_publishes_is_the_stable_tag(): void
    {
        $deploy = $this->workflow('deploy.yml');

        $matched = preg_match('/^\s*VERSION:\s*(.+)$/m', $deploy, $m);
        $this->assertSame(1, $matched, 'deploy.yml no longer sets VERSION for the publish step.');

        $version = trim($m[1]);

        $this->assertStringNotContainsString(
            'github.ref_name',
            $version,
            'VERSION is the raw tag, so a v1.0.0 tag publishes tags/v1.0.0 while Stable tag says 1.0.0.'
        );

        // The gate strips the v and checks it against both headers, so that is
        // the one value already known to agree with Stable tag.
        $this->assertMatchesRegularExpression(
            '/steps\.version\.outputs\.version/',
            $version,
            'VERSION has to be the value the version gate checked against Stable tag.'
        );

        $this->assertMatchesRegularExpression(
            '/id:\s*version/',
            $deploy,
            'nothing in deploy.yml produces that output.'
        );
        $this->assertStringContainsString(
            'version=$TAG" >> "$GITHUB_OUTPUT"',
            $deploy,
            'the version gate computes the stripped tag but never publishes it.'
        );
    }

    /**
     * The packager names the zip after the Text Domain, and the workflows have
     * to ask for that file: upload-artifact treats a path that matches nothing
     * as a warning, so the run goes green with no artefact attached.
     */
    public function test_every_workflow_uploads_the_zip_the_packager_produces(): void
    {
        $expected = $this->zipName();
        $wrong    = [];

        foreach (['deploy.yml', 'build-zip.yml'] as $name) {
            $body = $this->workflow($name);
            preg_match_all('/^\s*path:\s*(dist\/\S+\.zip)\s*$/m', $body, $m);

            foreach ($m[1] as $path) {
                if ($path !== 'dist/' . $expected) {
                    $wrong[] = "$name: $path";
                }
            }
        }

        $this->assertSame(
            [],
            $wrong,
            "these workflows upload a zip bin/package.mjs never writes:\n" . implode("\n", $wrong)
        );
    }

    /** A missing file has to fail the run, not warn inside it. */
    public function test_an_upload_that_finds_nothing_fails_the_run(): void
    {
        foreach (['deploy.yml', 'build-zip.yml'] as $name) {
            $this->assertStringContainsString(
                'if-no-files-found: error',
                $this->workflow($name),
                "$name would report success after uploading nothing."
            );
        }
    }
}
