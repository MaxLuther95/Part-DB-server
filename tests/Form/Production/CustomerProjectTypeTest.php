<?php

declare(strict_types=1);

namespace App\Tests\Form\Production;

use App\Form\Production\CustomerProjectType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

final class CustomerProjectTypeTest extends TestCase
{
    public function testOptionalOrderDatesCanRenderInTheEffectiveLocalTimezone(): void
    {
        $builder = $this->createMock(FormBuilderInterface::class);
        $dateOptions = [];
        $builder->method('add')
            ->willReturnCallback(
                static function (string $name, ?string $type, array $options) use ($builder, &$dateOptions): FormBuilderInterface {
                    if (in_array($name, ['orderDate', 'plannedDeliveryDate'], true)) {
                        self::assertSame(DateType::class, $type);
                        $dateOptions[$name] = $options;
                    }

                    return $builder;
                },
            );

        (new CustomerProjectType())->buildForm($builder, []);

        self::assertSame(['orderDate', 'plannedDeliveryDate'], array_keys($dateOptions));

        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $date = new \DateTimeImmutable('2026-09-02', new \DateTimeZone('Europe/Berlin'));
            foreach ($dateOptions as $options) {
                self::assertFalse($options['required']);
                self::assertArrayNotHasKey('model_timezone', $options);
                $form = Forms::createFormFactory()->create(DateType::class, $date, $options);

                self::assertSame('2026-09-02', $form->createView()->vars['value']);
            }
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }
}
