<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use DOMDocument;
use DOMElement;
use Gratora\Admin\AdminMenu;

/**
 * The Fundraising menu carries Gratora's own mark. WordPress colours a menu
 * icon to the admin's colour scheme only when it is an SVG handed over as a
 * base64 data URI, and it does that by rewriting the fill of each shape.
 */
final class TheMenuCarriesTheMarkTest extends IntegrationTestCase
{
    private const SHAPES = ['path', 'rect', 'circle', 'ellipse', 'polygon', 'polyline', 'line'];

    protected function setUp(): void
    {
        parent::setUp();

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function icon(): string
    {
        (new AdminMenu())->registerMenu();

        foreach ((array) $GLOBALS['menu'] as $item) {
            if (($item[2] ?? '') === 'gratora') {
                return (string) $item[6];
            }
        }

        $this->fail('The Fundraising menu was not registered.');
    }

    private function drawing(): string
    {
        $icon = $this->icon();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $icon);

        return (string) base64_decode(substr($icon, strlen('data:image/svg+xml;base64,')), true);
    }

    /** @return list<string> the fill of every shape the drawing holds */
    private function fills(string $svg): array
    {
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($svg), 'the icon is a well formed drawing');
        $this->assertSame('svg', $document->documentElement->localName);

        $fills = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && in_array($element->localName, self::SHAPES, true)) {
                $fills[] = $element->getAttribute('fill');
            }
        }

        return $fills;
    }

    public function test_the_icon_is_handed_over_in_the_form_wordpress_recolours(): void
    {
        // The pattern wp-admin/js/svg-painter.js reads a menu icon with.
        $this->assertMatchesRegularExpression('#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $this->icon());
    }

    public function test_the_icon_is_a_drawing_with_a_colour_of_its_own_to_start_from(): void
    {
        $fills = $this->fills($this->drawing());

        $this->assertNotSame([], $fills, 'the drawing holds a shape');
        $this->assertNotContains('', $fills, 'and every shape names its fill');
    }

    public function test_wordpress_recolours_every_shape_of_it(): void
    {
        // What svg-painter.js does to a menu icon for each colour of the admin's scheme.
        $painted = (string) preg_replace('/fill="(.+?)"/', 'fill="#72aee6"', $this->drawing());

        $this->assertSame(['#72aee6'], array_values(array_unique($this->fills($painted))));
    }
}
