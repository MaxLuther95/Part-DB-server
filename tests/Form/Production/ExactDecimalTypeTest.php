<?php

declare(strict_types=1);

namespace App\Tests\Form\Production;

use App\Entity\Production\ProtocolAnswer;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplateField;
use App\Form\Production\ExactDecimalType;
use App\Helpers\Production\DecimalInput;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class ExactDecimalTypeTest extends KernelTestCase
{
    public static function validNumbers(): iterable
    {
        yield ['1,230', '1.230'];
        yield ['12.34', '12.34'];
        yield ['0', '0'];
        yield ['0.000', '0.000'];
        yield ['-0.000000000012300', '-0.000000000012300'];
        yield ['+00023,4500', '23.4500'];
        yield ['.50', '0.50'];
        yield ['5.', '5'];
        yield ['12345678901234567890123456789.1234567890123456789', '12345678901234567890123456789.1234567890123456789'];
    }

    #[DataProvider('validNumbers')]
    public function testFormAndEntityPreserveExactPrecision(string $input, string $expected): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $factory->create(ExactDecimalType::class, null, ['csrf_protection' => false]);
        $form->submit($input);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($expected, $form->getData());
        $answer = (new ProtocolAnswer())->setField((new ProtocolTemplateField())->setType(ProtocolFieldType::Decimal));
        $answer->setValue($input);
        self::assertSame($expected, $answer->getValue());
        $reopened = $factory->create(ExactDecimalType::class, $answer->getValue(), ['csrf_protection' => false]);
        self::assertSame($expected, $reopened->getViewData());
    }

    public function testInvalidNumbersAreRejectedAndDoNotClearPreviousAnswer(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        $answer = (new ProtocolAnswer())->setField((new ProtocolTemplateField())->setType(ProtocolFieldType::Decimal));
        $answer->setValue('1.230');
        foreach (['1e3', 'NaN', 'INF', '1,234.56', '1.234,56', '1 234', '.', '--1', '<script>', str_repeat('1', 129)] as $input) {
            $form = $factory->create(ExactDecimalType::class, '1.230', ['csrf_protection' => false]);
            $form->submit($input);
            self::assertFalse($form->isValid(), $input);
            try {
                $answer->setValue($input);
                self::fail('Invalid decimal accepted: '.$input);
            } catch (\InvalidArgumentException) {
                self::assertSame('1.230', $answer->getValue());
            }
        }
        $form = $factory->create(ExactDecimalType::class, '1.230', ['required' => false, 'csrf_protection' => false]);
        $form->submit('');
        self::assertTrue($form->isValid());
        self::assertNull($form->getData());
        self::assertSame('1.230', DecimalInput::normalize('1.230'));
    }
}
