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

        public function add(): bool
        {
            $this->calls[] = 'add';
            $this->id = 77;

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

            return true;
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
            return ['table' => 'product', 'primary' => 'id_product'];
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
    }
}

if (! class_exists('Context', false)) {
    class Context
    {
    }
}
