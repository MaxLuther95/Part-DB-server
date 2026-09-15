<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtocolFieldValidationTest extends WebTestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function invalidSelections(): iterable
    {
        foreach ([false, true] as $create) {
            foreach (['type', 'layoutColumns'] as $property) {
                yield ($create ? 'create ' : 'edit ').$property.' empty' => [$property, '', $create];
                yield ($create ? 'create ' : 'edit ').$property.' missing' => [$property, null, $create];
                yield ($create ? 'create ' : 'edit ').$property.' unknown' => [$property, 'not-a-choice', $create];
            }
        }
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionShowsValidationInsteadOfServerError(string $property, ?string $value, bool $create): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(User::class, $admin);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        $template = (new ProtocolTemplate())->setName('Field validation '.bin2hex(random_bytes(4)));
        $revision = new ProtocolTemplateRevision();
        $section = (new ProtocolTemplateSection())->setName('Measurements');
        $field = (new ProtocolTemplateField())->setLabel('Voltage')->setType(ProtocolFieldType::Decimal)->setLayoutColumns(9);
        $template->addRevision($revision);
        $revision->addSection($section);
        $section->addField($field);
        $em->persist($template);
        $em->flush();
        $fieldId = $field->getId();
        $fieldCount = $em->getRepository(ProtocolTemplateField::class)->count([]);
        $url = $create ? '/en/production/protocol-sections/'.$section->getId().'/fields/new' : '/en/production/protocol-fields/'.$fieldId.'/edit';
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $crawler->selectButton('Save draft')->form()->getPhpValues();
        $data['protocol_template_field']['label'] = 'Must not be saved';
        if (null === $value) {
            unset($data['protocol_template_field'][$property]);
        } else {
            $data['protocol_template_field'][$property] = $value;
        }
        $client->request('POST', $url, $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(ProtocolTemplateField::class, $fieldId);
        self::assertInstanceOf(ProtocolTemplateField::class, $saved);
        self::assertSame('Voltage', $saved->getLabel());
        self::assertSame(ProtocolFieldType::Decimal, $saved->getType());
        self::assertSame(9, $saved->getLayoutColumns());
        self::assertSame($fieldCount, $em->getRepository(ProtocolTemplateField::class)->count([]));
    }
}
