<?php
    /**
     * Created by PhpStorm (Carlos Parra).
     * User: zyos
     * Email: neural.framework@gmail.com
     * Date: 15/04/22
     * Time: 12:03 a. m.
     */
    namespace Zyos\InstallBundle;

    use ArrayIterator;
    use Exception;
    use JetBrains\PhpStorm\Pure;
    use Traversable;

    /**
     * Class ParameterBag
     *
     * @package Zyos\InstallBundle
     */
    class ParameterBag implements \IteratorAggregate, \Countable {

        /**
         * @const int ORDER_ASC
         */
        const ORDER_ASC = 1;

        /**
         * @const int ORDER_DESC
         */
        const ORDER_DESC = 2;

        /**
         * @var array
         */
        private array $params;

        /**
         * Constructor ParameterBag
         *
         * @param array $params
         */
        public function __construct(array $params = []) {
            $this->params = $params;
        }

        /**
         * validate key exists
         *
         * @param string|int $key
         *
         * @return bool
         */
        public function has(string|int $key): bool {
            return array_key_exists($key, $this->params);
        }

        /**
         * Get value of key
         *
         * @param string|int $key
         * @param false $default
         *
         * @return mixed
         */
        #[Pure] public function get(string|int $key, mixed $default = false): mixed {
            return $this->has($key) ? $this->params[$key] : $default;
        }

        /**
         * Set value
         *
         * @param string|int $key
         * @param mixed $value
         *
         * @return void
         */
        public function set(string|int $key, mixed $value): void {
            $this->params[$key] = $value;
        }

        /**
         * Get all data from data array
         *
         * @return array
         */
        public function all(): array {
            return $this->params;
        }

        /**
         * Get the values of the data array
         *
         * @return array
         */
        public function values(): array {
            return array_values($this->params);
        }

        /**
         * Get the keys of the data array
         *
         * @return array
         */
        public function keys(): array {
            return array_keys($this->params);
        }

        /**
         * Search the data array if the matching
         * value exists
         *
         * @param mixed $value
         *
         * @return bool
         */
        public function in(mixed $value): bool {
            return in_array($value, $this->params);
        }

        /**
         * Search for data in the data array of
         * the selected key
         *
         * @param string|int $key
         * @param mixed $value
         *
         * @return bool
         */
        public function inKey(string|int $key, mixed $value): bool {
            return in_array($value, $this->params[$key]);
        }

        /**
         * Filter array data
         *
         * @param callable $callback
         *
         * @return $this
         */
        public function filter(callable $callback): self {
            return new self(array_values(array_filter($this->params, $callback)));
        }

        /**
         * Order by column
         *
         * @param string|int $key
         * @param int $order
         *
         * @return ParameterBag
         */
        public function orderByColumn(string|int $key, int $order = self::ORDER_ASC): self {

            if ($this->count() > 0):

                $array = [];
                foreach ($this->params AS $item):
                    $array[$item[$key]][] = $item;
                endforeach;

                $function = self::ORDER_ASC === $order ? 'ksort' : 'krsort';
                call_user_func_array($function, [&$array]);
                return new self($array);

            endif;

            return new self([]);
        }

        /**
         * Get self
         *
         * @param string|int $key
         *
         * @return $this
         */
        #[Pure] public function self(string|int $key): self {
            return new self($this->get($key, []));
        }

        /**
         * Retrieve an external iterator
         *
         * @link https://php.net/manual/en/iteratoraggregate.getiterator.php
         * @return Traversable|TValue[] An instance of an object implementing <b>Iterator</b> or
         * <b>Traversable</b>
         * @throws Exception on failure.
         */
        public function getIterator(): ArrayIterator {
            return new ArrayIterator($this->params);
        }

        /**
         * Count elements of an object
         *
         * @link https://php.net/manual/en/countable.count.php
         * @return int The custom count as an integer.
         * <p>
         * The return value is cast to an integer.
         * </p>
         */
        public function count(): int {
            return \count($this->params);
        }

        /**
         * Method first
         *
         * @return false|mixed
         */
        public function first(): mixed {

            $values = $this->values();
            return $values[0] ?? false;
        }
    }