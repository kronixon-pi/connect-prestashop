<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Write\Fields;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\ItemError;
use Kronixon\Connect\Write\Texts;
use Kronixon\Connect\Write\Warnings;
use ObjectModel;
use PHPUnit\Framework\TestCase;
use Product;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * Items read member by member, texts by language and the fields an update writes.
 */
final class FieldsTest extends TestCase
{
    /** @var Warnings */
    private $warnings;

    /** @var list<ObjectModel> */
    private $created = [];

    protected function setUp(): void
    {
        $this->warnings = new Warnings();
    }

    public function test_an_update_writes_only_what_changed_in_the_languages_sent(): void
    {
        $product = $this->product();
        $fields = $this->fields($product);
        $fields->text('name', [2 => 'Нов']);
        $fields->text('description', [1 => '<b>Same</b>'], true);
        $fields->set('reference', 'R-1');
        $fields->set('active', true);
        $fields->amount('price', '99.99');

        self::assertTrue($fields->changed(['name']));
        self::assertFalse($fields->changed(['reference', 'active', 'price', 'description']));
        $fields->save('product');
        self::assertSame(['name' => [2 => true]], $product->update_fields);
        self::assertSame([1 => 'Old', 2 => 'Нов'], $product->name);
        self::assertSame(['update'], $product->calls);
    }

    public function test_nothing_changed_means_no_save(): void
    {
        $product = $this->product();
        $fields = $this->fields($product);
        $fields->text('name', [1 => 'Old']);
        $fields->text('name', null);
        $fields->set('reference', null);
        $fields->amount('price', '99.990000');
        $fields->save('product');

        self::assertFalse($fields->changed());
        self::assertSame([], $product->calls);
    }

    public function test_a_text_in_another_unicode_form_is_the_same_text(): void
    {
        if (! class_exists(\Normalizer::class)) {
            self::markTestSkipped('needs intl');
        }
        $product = $this->product();
        $product->name = [1 => "Caf\u{e9}", 2 => 'x'];
        $fields = $this->fields($product);
        $fields->text('name', [1 => "Cafe\u{301}"]);

        self::assertFalse($fields->changed());
    }

    public function test_values_as_text_and_as_native_numbers_compare_alike(): void
    {
        foreach ([['1', '99.990000'], [1, 99.99]] as [$active, $price]) {  // 1.7.8 reads text, 9.x numbers
            $product = $this->product();
            $product->active = $active;
            $product->price = $price;
            $fields = $this->fields($product);
            $fields->set('active', true);
            $fields->amount('price', '99.99');
            self::assertFalse($fields->changed());
            $fields->amount('price', '99.989999');
            self::assertTrue($fields->changed(['price']));
        }
    }

    public function test_a_create_fills_the_other_languages_and_is_reported(): void
    {
        $product = new Product();
        $fields = $this->fields($product);
        $fields->text('name', [1 => 'New']);
        $fields->save('product');

        self::assertSame([1 => 'New', 2 => 'New'], $product->name);
        self::assertSame(['add'], $product->calls);
        self::assertSame([$product], $this->created);
    }

    public function test_prestashops_validation_is_asked_before_the_save(): void
    {
        $product = $this->product();
        $product->valid = 'Property Product->reference is not valid';
        $fields = $this->fields($product);
        $fields->set('reference', 'new');
        try {
            $fields->save('product');
            self::fail('saved');
        } catch (ItemError $error) {
            self::assertSame(['validation_failed', 'reference'], [$error->itemCode(), $error->result()['field']]);
        }
        self::assertSame([], $product->calls);
        self::assertSame(['reference' => true], $product->update_fields, 'only the changed fields are validated');
    }

    public function test_a_failed_save_is_an_error_and_a_created_row_is_known(): void
    {
        $product = new Product();
        $product->saves = false;
        $this->expectException(ItemError::class);
        try {
            $this->fields($product)->save('product');
        } finally {
            self::assertSame([$product], $this->created);
        }
    }

    public function test_texts_of_an_item(): void
    {
        $texts = $this->texts();
        $item = new Item(['name' => ['en' => "  Two\n lines ", 'fr' => 'x'], 'meta_title' => ['en' => 'A <b> = {c}'], 'empty' => ['bg' => ' ']]);

        self::assertSame([1 => 'Two lines'], $texts->name($item, 'name', true));
        self::assertSame(['language_unknown'], $this->warnings->take());
        self::assertSame([1 => 'A  b    '], array_map(static function (string $t): string {
            return substr($t, 0, 8);
        }, (array) $texts->meta($item, 'meta_title', 20)));
        self::assertSame([], $this->warnings->take());
        self::assertSame([1 => 'A'], $texts->meta($item, 'meta_title', 1));
        self::assertSame(['meta_title_cut'], $this->warnings->take());
        self::assertNull($texts->of($item, 'absent'));
        foreach (['empty' => 'empty.bg', 'absent' => 'absent.en'] as $member => $field) {
            try {
                $texts->name($item, $member, true);
                self::fail($member);
            } catch (ItemError $error) {
                self::assertSame($field, $error->result()['field']);
            }
        }
        $this->expectException(ItemError::class);
        $this->texts(true)->of($item, 'name');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, list<mixed>}>
     */
    public function wrongMembers(): iterable
    {
        yield 'a number for a text' => [['a' => 1], 'string', ['a']];
        yield 'too long' => [['a' => 'xx'], 'string', ['a', 1]];
        yield 'a number for an id' => [['a' => 5], 'id', ['a']];
        yield 'an id with a zero' => [['a' => '05'], 'id', ['a']];
        yield 'text for a flag' => [['a' => 'true'], 'bool', ['a']];
        yield 'a float for a count' => [['a' => 1.5], 'int', ['a']];
        yield 'below the minimum' => [['a' => 0], 'int', ['a', 1]];
        yield 'a number for an amount' => [['a' => 9.99], 'money', ['a']];
        yield 'a comma' => [['a' => '9,99'], 'money', ['a']];
        yield 'seven decimals' => [['a' => '1.1234567'], 'money', ['a']];
        yield 'another choice' => [['a' => 'x'], 'choice', ['a', ['y']]];
        yield 'a map for a list' => [['a' => ['x' => 'y']], 'strings', ['a']];
        yield 'numbers in a list' => [['a' => [1]], 'strings', ['a']];
        yield 'a text for texts' => [['a' => 'x'], 'texts', ['a']];
        yield 'a locale' => [['a' => ['en_GB' => 'x']], 'texts', ['a']];
        yield 'a list for an object' => [['a' => ['x']], 'object', ['a']];
        yield 'texts for objects' => [['a' => ['x']], 'objects', ['a']];
        yield 'no uuid' => [['kx_id' => 'x'], 'kxId', []];
    }

    /**
     * @dataProvider wrongMembers
     *
     * @param  array<string, mixed>  $data
     * @param  list<mixed>  $arguments
     */
    public function test_a_member_of_the_wrong_type_is_named(array $data, string $method, array $arguments): void
    {
        try {
            (new Item($data, 'seo'))->{$method}(...$arguments);
            self::fail('accepted');
        } catch (ItemError $error) {
            self::assertSame('validation_failed', $error->itemCode());
            self::assertStringStartsWith('seo.', $error->result()['field']);
        }
    }

    public function test_ids(): void
    {
        $item = new Item(['a' => '12', 'zero' => '0', 'null' => null, 'huge' => '99999999999999999999']);

        self::assertSame(12, $item->id('a'));
        self::assertNull($item->id('zero'));
        self::assertNull($item->id('null'));
        self::assertNull($item->id('absent'));
        self::assertSame(PHP_INT_MAX, $item->id('huge'));
        self::assertTrue($item->has('null'));
        self::assertFalse($item->has('absent'));
    }

    private function product(): Product
    {
        $product = new Product();
        $product->id = 5;
        $product->name = [1 => 'Old', 2 => 'Стар'];
        $product->description = [1 => '<b>same</b>'];
        $product->reference = 'R-1';
        $product->active = '1';
        $product->price = '99.990000';

        return $product;
    }

    private function texts(bool $strict = false): Texts
    {
        return new Texts((new CatalogDb())->scope(), $this->warnings, $strict);
    }

    private function fields(Product $product): Fields
    {
        return new Fields($product, $this->texts(), 'strtolower', function (ObjectModel $object): void {
            $this->created[] = $object;
        });
    }
}
