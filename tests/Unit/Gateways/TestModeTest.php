<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Forms\Form;
use Dono\Forms\FormRepository;
use Dono\Gateways\TestMode;
use PHPUnit\Framework\TestCase;

final class TestModeTest extends TestCase
{
    private TestMode $tm;

    protected function setUp(): void
    {
        $GLOBALS['_dono_test_options'] = [];
        // forForm() never touches the repository; forFormId() (DB) is covered
        // end-to-end by the integration suite.
        $this->tm = new TestMode(new FormRepository());
    }

    public function test_off_by_default(): void
    {
        $this->assertFalse($this->tm->forForm(null));
        $this->assertFalse($this->tm->forForm(Form::make()));
    }

    public function test_form_setting_opts_in(): void
    {
        $form = Form::make();
        $form->settings = ['test_mode' => true];
        $this->assertTrue($this->tm->forForm($form));
    }

    public function test_global_kill_switch_wins_when_form_not_opted_in(): void
    {
        update_option('dono_gateway_config', ['test_mode' => true]);

        $this->assertTrue($this->tm->forForm(null));

        $form = Form::make();
        $form->settings = ['test_mode' => false];
        $this->assertTrue($this->tm->forForm($form));
    }
}
