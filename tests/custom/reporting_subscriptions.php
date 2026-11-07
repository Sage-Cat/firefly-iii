<?php

declare(strict_types=1);

namespace Carbon {
    class Carbon extends \DateTime
    {
        public static function parse($value): self
        {
            return new self((string) $value);
        }

        public function addDay(): self
        {
            $this->modify('+1 day');
            return $this;
        }

        public function copy(): self
        {
            return clone $this;
        }

        public function setTimezone($timezone): static
        {
            parent::setTimezone(is_string($timezone) ? new \DateTimeZone($timezone) : $timezone);
            return $this;
        }

        public function subDay(): self
        {
            $this->modify('-1 day');
            return $this;
        }
    }
}

namespace FireflyIII\Support\Reports {
    class ReportingCurrency
    {
        public static function convert($amount, $from, $to)
        {
            return CurrencyBridge::convert($amount, $from, $to, ['USD' => '45', 'EUR' => '50', 'UAH' => '1']);
        }

        public static function currency($code)
        {
            return (object) ['id' => 4, 'code' => $code, 'symbol' => '₴'];
        }
    }
}

namespace FireflyIII\Support\Facades {
    class Amount
    {
        public static function formatAnything($currency, $value, $colored)
        {
            return $value . ' ' . $currency->code;
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ReportingSubscriptions.php';
    use Carbon\Carbon;
    use FireflyIII\Support\Reports\ReportingSubscriptions;

    class FixtureCollection
    {
        public function __construct(
            public array $values
        ) {}

        public function all()
        {
            return $this->values;
        }

        public function count()
        {
            return count($this->values);
        }

        public function pluck($key)
        {
            return new self(array_column($this->values, $key));
        }
    }

    function app($interface)
    {
        return new class {
            public function getAccountsByType($types)
            {
                return new FixtureCollection([['id' => 'owned']]);
            }

            public function setUser($user) {}
        };
    }

    function auth()
    {
        return new class {
            public function user()
            {
                return (object) ['id' => 'owner'];
            }
        };
    }

    function config($key)
    {
        return ['Asset account'];
    }

    function trans($key, $parameters)
    {
        return $key;
    }

    class JournalQuery
    {
        public function __construct(
            private array $rows
        ) {}

        public function after($date)
        {
            return $this;
        }

        public function before($date)
        {
            return $this;
        }

        public function get()
        {
            return $this->rows;
        }

        public function whereHas($relation)
        {
            if ($relation !== 'transactionGroup') {
                throw new \RuntimeException('Wrong deleted-group scope.');
            }
            return $this;
        }
    }

    class Journal
    {
        public object $transactionType;

        public function __construct(
            public string $date,
            public object $leg,
            string $type = 'Withdrawal'
        ) {
            $this->transactionType = (object) ['type' => $type];
        }

        public function transactions()
        {
            return new class($this->leg) {
                public function __construct(
                    private object $leg
                ) {}

                public function first()
                {
                    return $this->leg;
                }

                public function where($key, $operator, $value)
                {
                    if ($key !== 'amount' || $operator !== '<' || $value !== 0) {
                        throw new \RuntimeException('Must read original debit leg.');
                    }
                    return $this;
                }
            };
        }
    }

    class Bill
    {
        public string $native_amount_min = '100';
        public string $native_amount_max = '100';
        public object $transactionCurrency;

        public function __construct(
            public bool $active,
            public string $amount_min,
            public string $amount_max,
            string $code,
            public int $expected,
            private array $journals = []
        ) {
            $this->transactionCurrency = (object) ['code' => $code];
        }

        public function transactionJournals()
        {
            return new JournalQuery($this->journals);
        }
    }

    function leg($amount, $code, $owner = 'owned')
    {
        return (object) [
            'amount'              => $amount,
            'account_id'          => $owner,
            'transactionCurrency' => (object) ['code' => $code],
            'native_amount'       => '-99999',
            'foreign_amount'      => '-9999'
        ];
    }

    $bills = [
        new Bill(true, '120', '120', 'USD', 1),
        new Bill(true, '120', '120', 'USD', 1, [new Journal('2026-11-03T12:00:00+02:00', leg('-5631', 'UAH'))]),
        new Bill(false, '240', '240', 'USD', 0, [new Journal('2026-11-02T12:00:00+02:00', leg('-240', 'USD'))]),
        new Bill(true, '10', '20', 'EUR', 2),
        new Bill(true, '120', '120', 'USD', 1, [new Journal('2026-11-03T12:00:00+02:00', leg('-999', 'USD', 'another-owner'))]),
        new Bill(true, '120', '120', 'USD', 1, [new Journal('2026-11-03T12:00:00+02:00', leg('-999', 'USD'), 'Deposit')]),
        new Bill(false, '1', '1', 'UAH', 0, [new Journal('2026-10-31T21:59:59Z', leg('-999', 'UAH'))])
    ];
    $repository = new class($bills) {
        public function __construct(
            private array $bills
        ) {}

        public function getBills()
        {
            return $this->bills;
        }

        public function getPayDatesInRange($bill, $start, $end)
        {
            return new FixtureCollection(array_fill(0, $bill->expected, 'date'));
        }

        public function sumPaidInRange($start, $end)
        {
            throw new \RuntimeException('Stored primary amounts must never be read.');
        }

        public function sumUnpaidInRange($start, $end)
        {
            throw new \RuntimeException('Stored primary amounts must never be read.');
        }
    };
    $start  = new Carbon('2026-11-01');
    $end    = new Carbon('2026-11-30');
    $before = serialize($bills);
    $rows   = ReportingSubscriptions::rows($repository, $start, $end);
    if ($rows[0]['monetary_value'] !== '16431.00' || $rows[1]['monetary_value'] !== '-17700.00') {
        throw new \RuntimeException('Paid debit legs and original forecast prices must use the current bridge.');
    }
    if ($rows[0]['currency_code'] !== 'UAH' || $rows[1]['currency_code'] !== 'UAH') {
        throw new \RuntimeException('Subscription spending must be UAH.');
    }
    if ($before !== serialize($bills)) {
        throw new \RuntimeException('Original/native bill data changed.');
    }
    if ($start->format('Y-m-d') !== '2026-11-01' || $end->format('Y-m-d') !== '2026-11-30') {
        throw new \RuntimeException('The period was mutated.');
    }
    echo "Passed 4 original-currency subscription projection checks.\n";
}
