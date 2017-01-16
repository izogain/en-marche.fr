<?php

namespace Tests\AppBundle\Controller;

use AppBundle\DataFixtures\ORM\LoadAdherentData;
use AppBundle\Entity\ActivationKey;
use AppBundle\Entity\Adherent;
use AppBundle\Repository\ActivationKeyRepository;
use AppBundle\Repository\AdherentRepository;
use AppBundle\Repository\MailjetEmailRepository;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\HttpFoundation\Request;

class MembershipControllerTest extends AbstractControllerTest
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var AdherentRepository
     */
    private $adherentRepository;

    /**
     * @var ActivationKeyRepository
     */
    private $activationKeyRepository;

    /**
     * @var MailjetEmailRepository
     */
    private $emailRepository;

    /**
     * @var ObjectManager
     */
    private $manager;

    /**
     * @dataProvider provideEmailAddress
     */
    public function testCannotCreateMembershipAccountWithSomeoneElseEmailAddress($emailAddress)
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/inscription');

        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $data = static::createFormData();
        $data['membership_request']['emailAddress'] = $emailAddress;
        $crawler = $this->client->submit($crawler->selectButton('become-adherent')->form(), $data);

        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertSame('Cette adresse e-mail existe déjà.', $crawler->filter('#field-email-address > .form__errors > li')->text());
    }

    /**
     * These data come from the LoadAdherentData fixtures file.
     *
     * @see LoadAdherentData
     */
    public function provideEmailAddress()
    {
        return [
            ['michelle.dufour@example.ch'],
            ['carl999@example.fr'],
        ];
    }

    public function testCannotCreateMembershipAccountIfAdherentIsUnder15YearsOld()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/inscription');

        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $data = static::createFormData();
        $data['membership_request']['birthdate'] = date('d/m/Y');
        $crawler = $this->client->submit($crawler->selectButton('become-adherent')->form(), $data);

        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertSame("Vous devez être âgé d'au moins 15 ans pour adhérer.", $crawler->filter('#field-birthdate > .form__errors > li')->text());
    }

    public function testCannotCreateMembershipAccountIfConditionsAreNotAccepted()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/inscription');

        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $data = static::createFormData();
        $data['membership_request']['conditions'] = false;
        $crawler = $this->client->submit($crawler->selectButton('become-adherent')->form(), $data);

        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertSame('Vous devez accepter la charte.', $crawler->filter('#field-conditions > .form__errors > li')->text());
    }

    public function testCannotCreateMembershipAccountWithInvalidFrenchAddress()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/inscription');

        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $data = static::createFormData();
        $data['membership_request']['postalCode'] = '73100';
        $data['membership_request']['city'] = '73100-73999';
        $crawler = $this->client->submit($crawler->selectButton('become-adherent')->form(), $data);

        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertSame("Cette valeur n'est pas un identifiant valide de ville française.", $crawler->filter('#app-membership > .form__errors > li')->text());
    }

    public function testCreateMembershipAccountForFrenchAdherentIsSuccessful()
    {
        $this->client->request(Request::METHOD_GET, '/inscription');
        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $this->client->submit($this->client->getCrawler()->selectButton('become-adherent')->form(), static::createFormData());
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $crawler = $this->client->followRedirect();

        $this->assertContains(
            "Votre inscription en tant qu'adhérent s'est déroulée avec succès.",
            $crawler->filter('#notice-flashes')->text()
        );

        $this->assertInstanceOf(Adherent::class, $adherent = $this->adherentRepository->findByEmail('paul@dupont.tld'));
        $this->assertInstanceOf(ActivationKey::class, $activationKey = $this->activationKeyRepository->findAdherentMostRecentKey((string) $adherent->getUuid()));
        $this->assertCount(1, $this->emailRepository->findAll());

        // Activate the user account
        $activateAccountUrl = sprintf('/inscription/finaliser/%s/%s', $adherent->getUuid(), $activationKey->getToken());
        $this->client->request(Request::METHOD_GET, $activateAccountUrl);
        $this->assertTrue($this->client->getResponse()->isRedirect());

        $crawler = $this->client->followRedirect();
        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertContains('Votre compte adhérent est maintenant actif.', $crawler->filter('#notice-flashes')->text());

        // Activate user account twice
        $this->client->request(Request::METHOD_GET, $activateAccountUrl);
        $this->assertTrue($this->client->getResponse()->isRedirect());

        $crawler = $this->client->followRedirect();
        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertContains('Votre compte adhérent est déjà actif.', $crawler->filter('#notice-flashes')->text());

        $this->manager->refresh($adherent);
        $this->manager->refresh($activationKey);

        // Try to authenticate with credentials
        $this->client->submit($crawler->selectButton('Je me connecte')->form([
            '_adherent_email' => 'paul@dupont.tld',
            '_adherent_password' => '#example!12345#',
        ]));

        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->client->followRedirect();
    }

    public function testCreateMembershipAccountForSwissAdherentIsSuccessful()
    {
        $this->client->request(Request::METHOD_GET, '/inscription');
        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $data = static::createFormData();
        $data['membership_request']['country'] = 'CH';
        $data['membership_request']['city'] = '';
        $data['membership_request']['postalCode'] = '';
        $data['membership_request']['address'] = '';

        $this->client->submit($this->client->getCrawler()->selectButton('become-adherent')->form(), $data);
        $this->assertTrue($this->client->getResponse()->isRedirect());

        $this->assertInstanceOf(Adherent::class, $adherent = $this->adherentRepository->findByEmail('paul@dupont.tld'));
        $this->assertInstanceOf(ActivationKey::class, $activationKey = $this->activationKeyRepository->findAdherentMostRecentKey((string) $adherent->getUuid()));
        $this->assertCount(1, $this->emailRepository->findAll());
    }

    private static function createFormData()
    {
        return [
            'membership_request' => [
                'gender' => 'male',
                'firstName' => 'Paul',
                'lastName' => 'Dupont',
                'emailAddress' => 'paul@dupont.tld',
                'password' => [
                    'first' => '#example!12345#',
                    'second' => '#example!12345#',
                ],
                'country' => 'FR',
                'postalCode' => '92110',
                'city' => '92110-92024',
                'address' => '92 Bld Victor Hugo',
                'phone' => [
                    'country' => 'FR',
                    'number' => '0140998080',
                ],
                'position' => 'retired',
                'birthdate' => '20/01/1950',
                'conditions' => true,
            ],
        ];
    }

    protected function setUp()
    {
        parent::setUp();

        $this->loadFixtures([
            LoadAdherentData::class,
        ]);

        $this->client = static::createClient();
        $this->container = $this->client->getContainer();
        $this->manager = $this->container->get('doctrine.orm.entity_manager');
        $this->adherentRepository = $this->getAdherentRepository();
        $this->activationKeyRepository = $this->getActivationKeyRepository();
        $this->emailRepository = $this->getMailjetEmailRepository();
    }

    protected function tearDown()
    {
        $this->loadFixtures([]);

        $this->manager = null;
        $this->emailRepository = null;
        $this->activationKeyRepository = null;
        $this->adherentRepository = null;
        $this->container = null;
        $this->client = null;

        parent::tearDown();
    }
}