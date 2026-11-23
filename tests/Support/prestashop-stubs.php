<?php

declare(strict_types=1);

// The PrestaShop core classes the write engine names, as little of them as its unit tests need.

if (! class_exists('PrestaShopException', false)) {
    class PrestaShopException extends Exception
    {
    }
}

if (! class_exists('PrestaShopDatabaseException', false)) {
    class PrestaShopDatabaseException extends PrestaShopException
    {
    }
}

if (! class_exists('ObjectModel', false)) {
    abstract class ObjectModel
    {
        /** @var int|null */
        public $id;

        /** @var array<string, mixed>|null */
        public $update_fields;

        /** @var list<string> what was called */
        public $calls = [];

        /** @var string|true the answer of validateFields() */
        public $valid = true;

        /** @var bool the answer of add() and update() */
        public $saves = true;

        /** @var string|true the answer of validateField() */
        public $validField = true;

        /** @var (callable(ObjectModel): void)|null what add() does once the object has its id: rows, hooks */
        public $onAdd;

        /** @var (callable(ObjectModel): void)|null what delete() of any object does */
        public static $onDelete;

        /** @var list<ObjectModel> what was deleted */
        public static $deleted = [];

        /**
         * @param int|null $id
         */
        public function __construct($id = null)
        {
            $this->id = $id;
        }

        public function add(): bool
        {
            $this->calls[] = 'add';
            $this->id = 77;
            if ($this->onAdd !== null) {
                ($this->onAdd)($this);
            }

            return $this->saves;
        }

        public function update(): bool
        {
            $this->calls[] = 'update';

            return $this->saves;
        }

        public function delete(): bool
        {
            $this->calls[] = 'delete';
            self::$deleted[] = $this;
            if (self::$onDelete !== null) {
                (self::$onDelete)($this);
            }

            return true;
        }

        /**
         * @param mixed $value
         * @return string|true
         */
        public function validateField(string $field, $value, ?int $langId = null)
        {
            return $langId === 2 ? $this->validField : true;
        }

        /**
         * @param array<string, mixed>|null $fields
         */
        public function setFieldsToUpdate($fields): void
        {
            $this->update_fields = $fields;
        }

        /**
         * @return string|true
         */
        public function validateFields(bool $die = true, bool $return = false)
        {
            return $this->valid;
        }

        /**
         * @return string|true
         */
        public function validateFieldsLang(bool $die = true, bool $return = false)
        {
            return true;
        }

        /**
         * @param mixed $object
         * @return array<string, string>
         */
        public static function getDefinition($object): array
        {
            $table = ['Category' => 'category', 'Combination' => 'product_attribute'][is_object($object) ? get_class($object) : $object] ?? 'product';

            return ['table' => $table, 'primary' => 'id_'.$table];
        }
    }
}

if (! class_exists('Product', false)) {
    class Product extends ObjectModel
    {
        /** @var array<int, string>|null */
        public $name;

        /** @var array<int, string>|null */
        public $description;

        /** @var string|null */
        public $reference;

        /** @var mixed */
        public $price;

        /** @var mixed */
        public $active;

        /** @var int */
        public $id_category_default = 2;

        /** @var string */
        public $redirect_type = '404';

        /** @var int */
        public $id_type_redirected = 0;
    }
}

if (! class_exists('Tag', false)) {
    class Tag extends ObjectModel
    {
        /** @var list<array{0: string, 1: int, 2: list<string>}> what was asked: call, language, names */
        public static $asked = [];

        public static function deleteProductTagsInLang(int $productId, int $langId): bool
        {
            self::$asked[] = ['delete', $langId, []];

            return true;
        }

        /**
         * @param list<string> $names
         */
        public static function addTags(int $langId, int $productId, array $names): bool
        {
            self::$asked[] = ['add', $langId, $names];

            return true;
        }
    }
}

if (! class_exists('Validate', false)) {
    class Validate
    {
        public static function isGenericName(string $name): bool
        {
            return strpos($name, '<') === false;
        }
    }
}

if (! class_exists('Configuration', false)) {
    class Configuration
    {
        /** @var array<string, string> */
        public static $values = ['PS_REWRITING_SETTINGS' => '1'];

        /**
         * @return string|false
         */
        public static function get(string $key)
        {
            return self::$values[$key] ?? false;
        }
    }
}

if (! class_exists('Category', false)) {
    class Category extends ObjectModel
    {
    }
}

if (! class_exists('Combination', false)) {
    class Combination extends ObjectModel
    {
    }
}

if (! class_exists('Context', false)) {
    class Context
    {
        /** @var Link */
        public $link;

        public function __construct()
        {
            $this->link = new Link();
        }
    }
}

if (! class_exists('Link', false)) {
    class Link
    {
        public function getProductLink(int $id): string
        {
            return "https://shop.example.com/$id-product.html";
        }

        public function getCategoryLink(int $id): string
        {
            return "https://shop.example.com/$id-category";
        }
    }
}
