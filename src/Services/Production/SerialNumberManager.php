<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\SerialNumberRange;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SerialNumberManager
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function rangeFor(SystemTemplate|Project|null $content): ?SerialNumberRange
    {
        if (null === $content || null === $content->getId()) {
            return null;
        }

        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(SerialNumberRange::class, 'r')
            ->join('r.'.($content instanceof SystemTemplate ? 'systems' : 'projects'), 'c')
            ->where('c.id = :id')
            ->setParameter('id', $content->getId())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{prefix: string, number: string, digits: int}
     */
    public function suggest(SystemTemplate|Project|null $content, int $offset = 0): array
    {
        $range = $this->rangeFor($content);
        if (null === $range) {
            return [
                'prefix' => '',
                'number' => '',
                'digits' => 1,
            ];
        }
        $number = (int) $this->em->getConnection()
            ->fetchOne('SELECT next_number FROM production_serial_number_ranges WHERE id = ?', [$range->getId()]);
        while ($number <= 999999999) {
            $formatted = str_pad((string) $number, $range->getMinimumDigits(), '0', STR_PAD_LEFT);
            if (! $this->exists($range->getPrefix().'-'.$formatted) && false === $this->em->getConnection()->fetchOne('SELECT id FROM production_build_instances WHERE serial_number_range_id = ? AND serial_ordinal = ?', [$range->getId(), $number])) {
                if ($offset-- <= 0) {
                    return [
                        'prefix' => $range->getPrefix(),
                        'number' => $formatted,
                        'digits' => $range->getMinimumDigits(),
                    ];
                }
            }
            ++$number;
        }
        throw new \RuntimeException('Der Seriennummernkreis ist ausgeschöpft.');
    }

    public function combine(string $prefix, string $number): ?string
    {
        $prefix = strtoupper(trim($prefix));
        $number = trim($number);
        if ('' === $number) {
            return null;
        }
        if ('' !== $prefix && ! preg_match('/^[A-Z0-9]{3,4}$/D', $prefix)) {
            throw new \RuntimeException('Das Präfix muss aus 3–4 Buchstaben oder Ziffern bestehen.');
        }
        $serial = ('' === $prefix ? '' : $prefix.'-').$number;
        if (strlen($serial) > 128 || preg_match('/\s/u', $serial)) {
            throw new \RuntimeException('Die Seriennummer darf keine Leerzeichen enthalten und höchstens 128 Zeichen lang sein.');
        }

        return $serial;
    }

    /**
     * @return array{prefix: string, number: string}
     */
    public static function split(?string $serial): array
    {
        if (preg_match('/^([A-Z0-9]{3,4})-(.+)$/D', $serial ?? '', $matches)) {
            return [
                'prefix' => $matches[1],
                'number' => $matches[2],
            ];
        }

        return [
            'prefix' => '',
            'number' => $serial ?? '',
        ];
    }

    private function exists(string $serial, ?int $except = null): bool
    {
        return false !== $this->em->getConnection()
            ->fetchOne('SELECT id FROM production_build_instances WHERE serial_number = ? AND (? IS NULL OR id <> ?)', [$serial, $except, $except]);
    }

    public function validate(SystemTemplate|Project|null $content, ?string $serial): void
    {
        if (null === $serial) {
            return;
        }
        $range = $this->rangeFor($content);
        if (null === $range) {
            if (str_contains($serial, '-')) {
                throw new \RuntimeException('Bitte dem Bautyp zuerst einen Seriennummernkreis zuordnen.');
            }

            return;
        }
        $this->ordinal($serial, $range->getPrefix(), $range->getMinimumDigits());
    }

    private function ordinal(string $serial, string $prefix, int $digits): int
    {
        if (! preg_match('/^'.preg_quote($prefix, '/').'-(\d{1,9})$/D', $serial, $match)) {
            throw new \RuntimeException('Die Seriennummer passt nicht zum zugeordneten Nummernkreis. Bitte erneut prüfen.');
        }
        $number = (int) $match[1];
        if ($number < 1 || $match[1] !== str_pad((string) $number, $digits, '0', STR_PAD_LEFT)) {
            throw new \RuntimeException('Bitte die Nummer mit der konfigurierten Mindeststellenzahl angeben (ohne zusätzliche führende Nullen).');
        }

        return $number;
    }

    /**
     * Called inside the same transaction as saving the instance and withdrawing stock.
     */
    public function claim(BuildInstance $instance, bool $confirmed, ?string $previousSerial = null): void
    {
        try {
            $this->claimNumber($instance, $confirmed, $previousSerial);
        } catch (\Doctrine\DBAL\Exception\DriverException $error) {
            if (!in_array($error->getCode(), [1020, 1205, 1213], true)) {
                throw $error;
            }
            // MariaDB snapshot conflicts, lock timeouts and deadlocks require
            // rolling back the WHOLE caller transaction, never just this query.
            throw new \RuntimeException('Der Nummernkreis wird gleichzeitig bearbeitet oder verwendet. Es wurde nichts gespeichert. Bitte die Seriennummer erneut prüfen und den Vorgang wiederholen.', previous: $error);
        }
    }

    private function claimNumber(BuildInstance $instance, bool $confirmed, ?string $previousSerial): void
    {
        $db = $this->em->getConnection();
        if (! $db->isTransactionActive()) {
            throw new \LogicException('Serial numbers must be claimed in a transaction.');
        }
        $serial = $instance->getSerialNumber();
        if (null === $serial) {
            $instance->setSerialNumberRange(null)
                ->setSerialOrdinal(null);

            return;
        }
        if (! $confirmed) {
            throw new \RuntimeException('Bitte die Seriennummer prüfen und ausdrücklich bestätigen.');
        }
        if ($this->exists($serial, $instance->getId())) {
            throw new \RuntimeException('Diese Seriennummer ist bereits vergeben. Bitte eine andere Nummer prüfen und bestätigen.');
        }
        if (null !== $instance->getId() && $previousSerial === $serial) {
            return;
        }
        $content = $instance->getSystemTemplate() ?? $instance->getTemplateProject();
        $lock = $db->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
        $join = $content instanceof SystemTemplate ? 'production_serial_range_systems' : 'production_serial_range_projects';
        $column = $content instanceof SystemTemplate ? 'system_template_id' : 'project_id';
        $rangeId = $db->fetchOne('SELECT range_id FROM '.$join.' WHERE '.$column.' = ?'.$lock, [$content?->getId()]);
        // Lock by primary key, not a joined snapshot: concurrent counter changes
        // must be read after acquiring the range lock on MariaDB as well.
        $current = false === $rangeId ? false : $db->fetchAssociative('SELECT id, prefix, minimum_digits, next_number FROM production_serial_number_ranges WHERE id = ?'.$lock, [$rangeId]);
        if (false === $current) {
            $instance->setSerialNumberRange(null)
                ->setSerialOrdinal(null);
            if (str_contains($serial, '-')) {
                throw new \RuntimeException('Bitte dem Bautyp zuerst einen Seriennummernkreis zuordnen.');
            }

            return;
        }
        $range = $this->em->find(SerialNumberRange::class, $current['id']);
        $number = $this->ordinal($serial, (string) $current['prefix'], (int) $current['minimum_digits']);
        // A locking read sees concurrent commits even under MariaDB REPEATABLE READ.
        if (false !== $db->fetchOne('SELECT id FROM production_build_instances WHERE (serial_number = ? OR (serial_number_range_id = ? AND serial_ordinal = ?)) AND (? IS NULL OR id <> ?)'.$lock, [$serial, $current['id'], $number, $instance->getId(), $instance->getId()])) {
            throw new \RuntimeException('Diese Seriennummer wurde inzwischen vergeben. Bitte eine andere Nummer prüfen und bestätigen.');
        }
        $db->executeStatement('UPDATE production_serial_number_ranges SET next_number = ?, version = version + 1 WHERE id = ?', [max((int) $current['next_number'], $number + 1), $current['id']]);
        $instance->setSerialNumberRange($range)
            ->setSerialOrdinal($number);
    }
}
