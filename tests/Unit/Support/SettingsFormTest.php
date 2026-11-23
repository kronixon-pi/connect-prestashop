<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Support;

use Kronixon\Connect\Support\SettingsForm;
use PHPUnit\Framework\TestCase;

/**
 * The settings page acts only on a POST with the submit name in the POST body: a GET (a link clicked,
 * which PrestaShop 9.x redirects to its Symfony route with a valid token) never acts.
 */
final class SettingsFormTest extends TestCase
{
    public function test_a_post_with_a_submit_name_in_its_body_is_that_action(): void
    {
        foreach (SettingsForm::ACTIONS as $name) {
            self::assertSame($name, SettingsForm::action(['REQUEST_METHOD' => 'POST'], [$name => '1']));
        }
        self::assertSame(
            ['submitKxPlatformUrl', 'submitKxPair', 'submitKxUnpair', 'submitKxRotateCron', 'submitKxPing'],
            SettingsForm::ACTIONS,
            'the names of the buttons in views/templates/admin/configure.tpl'
        );
    }

    public function test_a_get_never_acts_whatever_it_carries(): void
    {
        foreach (SettingsForm::ACTIONS as $name) {
            self::assertNull(SettingsForm::action(['REQUEST_METHOD' => 'GET'], [$name => '1']), $name);
            self::assertNull(SettingsForm::action(['REQUEST_METHOD' => 'HEAD'], [$name => '1']), $name);
            self::assertNull(SettingsForm::action([], [$name => '1']), $name.' without a method');
        }
    }

    public function test_a_post_acts_only_on_a_name_in_its_body(): void
    {
        // $_GET is not read: a POST to a URL that carries the submit name does nothing.
        self::assertNull(SettingsForm::action(['REQUEST_METHOD' => 'POST'], []));
        self::assertNull(SettingsForm::action(['REQUEST_METHOD' => 'POST'], ['kx_code' => 'K7PM-3XQ9']));
        self::assertNull(SettingsForm::action(['REQUEST_METHOD' => 'post'], ['submitKxPing' => '1']), 'PHP gives the method as sent; only POST acts');
    }

    public function test_fields_are_text_from_the_post_body(): void
    {
        self::assertSame('https://pi.example', SettingsForm::field(['kx_platform_url' => 'https://pi.example'], 'kx_platform_url'));
        self::assertSame('', SettingsForm::field([], 'kx_code'));
        self::assertSame('', SettingsForm::field(['kx_code' => ['K7PM']], 'kx_code'), 'an array is not text');
    }

    public function test_the_template_has_a_button_for_every_action_and_no_other(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3).'/views/templates/admin/configure.tpl');
        preg_match_all('/name="(submitKx\w+)"/', $template, $names);
        self::assertEqualsCanonicalizing(SettingsForm::ACTIONS, array_values(array_unique($names[1])));
        self::assertSame(0, preg_match('/<form(?![^>]*method="post")/', $template), 'every form posts');
    }
}
