<?php
/**
 * Tests for the SwiftForms core bootstrap.
 */

declare(strict_types=1);

class SwiftForms_Test_CPTs_Double extends SwiftForms_CPTs {
    public int $register_calls = 0;

    public function register(): void {
        ++$this->register_calls;
    }
}

class SwiftForms_Test_Blocks_Double extends SwiftForms_Blocks {
    public int $register_calls = 0;

    public function __construct() {
    }

    public function register_blocks(): void {
        ++$this->register_calls;
    }
}

class SwiftForms_Core_Test extends WP_UnitTestCase {
    public function test_get_container_returns_same_instance(): void {
        $core = new SwiftForms_Core();

        $container_one = $core->get_container();
        $container_two = $core->get_container();

        $this->assertSame($container_one, $container_two);
    }

    public function test_get_container_persists_runtime_changes(): void {
        $core = new SwiftForms_Core();

        $container = $core->get_container();
        $container['runtime'] = 'value';

        $this->assertSame('value', $core->get_container()['runtime']);
    }

    public function test_init_registers_cpts_and_blocks(): void {
        $cpts = new SwiftForms_Test_CPTs_Double();
        $blocks = new SwiftForms_Test_Blocks_Double();
        $submissions = new SwiftForms_Submissions();

        $core = new SwiftForms_Core(
            array(
                'blocks' => $blocks,
                'cpts' => $cpts,
                'submissions' => $submissions,
            )
        );

        $core->init();

        $this->assertSame(1, $cpts->register_calls);
        $this->assertSame(1, $blocks->register_calls);
    }

    public function test_init_registers_ajax_hooks(): void {
        $core = new SwiftForms_Core(array('submissions' => new SwiftForms_Submissions()));

        $core->init();

        $this->assertNotFalse(has_action('wp_ajax_swiftforms_submit', array($core->get_container()['submissions'], 'handle_submission')));
        $this->assertNotFalse(has_action('wp_ajax_nopriv_swiftforms_submit', array($core->get_container()['submissions'], 'handle_submission')));
    }
}