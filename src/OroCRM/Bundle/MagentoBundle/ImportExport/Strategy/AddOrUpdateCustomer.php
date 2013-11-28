<?php

namespace OroCRM\Bundle\MagentoBundle\ImportExport\Strategy;

use Doctrine\ORM\EntityRepository;

use Oro\Bundle\AddressBundle\Entity\AbstractAddress;
use Oro\Bundle\AddressBundle\Entity\AddressType;
use Oro\Bundle\BatchBundle\Item\InvalidItemException;
use Oro\Bundle\ImportExportBundle\Context\ContextAwareInterface;
use Oro\Bundle\ImportExportBundle\Context\ContextInterface;
use Oro\Bundle\ImportExportBundle\Strategy\Import\ImportStrategyHelper;
use Oro\Bundle\ImportExportBundle\Strategy\StrategyInterface;

use OroCRM\Bundle\AccountBundle\Entity\Account;
use OroCRM\Bundle\AccountBundle\ImportExport\Serializer\Normalizer\AccountNormalizer;
use OroCRM\Bundle\ContactBundle\Entity\Contact;
use OroCRM\Bundle\ContactBundle\Entity\ContactAddress;
use OroCRM\Bundle\ContactBundle\ImportExport\Serializer\Normalizer\ContactNormalizer;
use OroCRM\Bundle\MagentoBundle\Entity\AddressRelation;
use OroCRM\Bundle\MagentoBundle\Entity\Customer;
use OroCRM\Bundle\MagentoBundle\Entity\CustomerGroup;
use OroCRM\Bundle\MagentoBundle\Entity\Store;
use OroCRM\Bundle\MagentoBundle\Entity\Website;
use OroCRM\Bundle\MagentoBundle\ImportExport\Serializer\CustomerNormalizer;

class AddOrUpdateCustomer implements StrategyInterface, ContextAwareInterface
{
    const ENTITY_NAME = 'OroCRMMagentoBundle:Customer';
    const GROUP_ENTITY_NAME = 'OroCRMMagentoBundle:CustomerGroup';
    const ADDRESS_RELATION_ENTITY = 'OroCRMMagentoBundle:AddressRelation';

    /** @var ImportStrategyHelper */
    protected $strategyHelper;

    /** @var ContextInterface */
    protected $importExportContext;

    /** @var array */
    protected $regionsCache = [];

    /** @var array */
    protected $mageRegionsCache = [];

    /**
     * @param ImportStrategyHelper $strategyHelper
     */
    public function __construct(ImportStrategyHelper $strategyHelper)
    {
        $this->strategyHelper = $strategyHelper;
    }

    /**
     * Process item strategy
     *
     * @param mixed $importedEntity
     * @return mixed|null
     */
    public function process($importedEntity)
    {
        $newEntity = $this->findAndReplaceEntity(
            $importedEntity,
            self::ENTITY_NAME,
            'originalId',
            ['id', 'contact', 'account', 'website', 'store', 'group']
        );

        // update all related entities
        $this->updateStoresAndGroup(
            $newEntity,
            $importedEntity->getStore(),
            $importedEntity->getWebsite(),
            $importedEntity->getGroup()
        );

        $this->updateContact($newEntity, $importedEntity->getContact())
             ->updateAccount($newEntity, $importedEntity->getAccount());

        die();
        // set relations
        if ($newEntity->getId()) {
            $newEntity->getContact()->addAccount($newEntity->getAccount());
            $newEntity->getAccount()->setDefaultContact($newEntity->getContact());
        }

        // validate and update context - increment counter or add validation error
        $this->validateAndUpdateContext($newEntity);

        return $newEntity;
    }

    /**
     * Update $entity with new data from imported $store, $website, $group
     *
     * @param Customer $entity
     * @param Store $store
     * @param Website $website
     * @param CustomerGroup $group
     *
     * @return $this
     */
    protected function updateStoresAndGroup(Customer $entity, Store $store, Website $website, CustomerGroup $group)
    {
        // do not allow to change code/website name by imported entity
        $doNotUpdateFields = ['id', 'code', 'name'];
        $entity
            ->setWebsite(
                $this->findAndReplaceEntity($website, CustomerNormalizer::WEBSITE_TYPE, 'code', $doNotUpdateFields)
            )
            ->setStore(
                $this->findAndReplaceEntity($store, CustomerNormalizer::STORE_TYPE, 'code', $doNotUpdateFields)
            )
            ->setGroup(
                $this->findAndReplaceEntity($group, CustomerNormalizer::GROUPS_TYPE, 'name', $doNotUpdateFields)
            );

        $entity->getStore()->setWebsite($entity->getWebsite());

        return $this;
    }

    /**
     * @param Customer $entity
     * @return $this
     */
    protected function updateContact(Customer $entity)
    {
        /** @var Contact $contact */
        $contact = $entity->getContact();

        /** @var Contact $newContact */
        $newContact = $this->findAndReplaceEntity($contact, ContactNormalizer::CONTACT_TYPE, 'id', ['id', 'addresses']);

        $existingAddressIds = [];
        $existingAddressEntities = [];
        foreach ($newContact->getAddresses() as $existingAddress) {
            $existingAddressIds[] = $existingAddress->getId();
            $existingAddressEntities[$existingAddress->getId()] = $existingAddress;
        }

        $originAddressIds = [];
        if (!empty($existingAddressIds)) {
            $originAddressIds = $this->getOriginAddressesIds($existingAddressIds);
        }

        // loop by imported addresses, update existing, add new
        foreach ($contact->getAddresses() as $address) {
            // at this point imported address region have code equal to region_id in magento db field
            $mageRegionId = $address->getRegion()->getCode();

            $originAddressId = $address->getId();
            $existingAddressId = empty($originAddressIds[$originAddressId]) ?
                null : $originAddressIds[$originAddressId];

            if (!empty($existingAddressId) && isset($existingAddressEntities[$existingAddressId])) {
                // so we have new data for existing address
                $this->strategyHelper->importEntity(
                    $existingAddressEntities[$existingAddressId],
                    $address,
                    ['id', 'country', 'state']
                );
                $address = $existingAddressEntities[$existingAddressId];
            } else {
                // it's not existing address
                $address->setId(null);
            }

            $this->updateAddressCountryRegion($address, $entity, $mageRegionId);

            // update address type
            $types = $address->getTypeNames();
            $address->getTypes()->clear();
            $loadedTypes = $this->getEntityRepository('OroAddressBundle:AddressType')
                ->findBy(['name' => $types]);
            foreach ($loadedTypes as $type) {
                $address->addType($type);
            }

            if (!$address->getId()) {
                $newContact->addAddress($address);
            }

            if (!in_array($originAddressId, array_keys($originAddressIds))) {
                $this->createOriginAddressRelation($address, $originAddressId);
            }
        }

        $entity->setContact($newContact);
        return $this;
    }
    
    /**
     * @param mixed $entity
     * @param string $entityName
     * @param string $idFieldName
     * @param array $excludedProperties
     * @return mixed
     */
    protected function findAndReplaceEntity($entity, $entityName, $idFieldName = 'id', $excludedProperties = [])
    {
        $existingEntity = $this->getEntityOrNull($entity, $idFieldName, $entityName);

        if ($existingEntity) {
            $this->strategyHelper->importEntity($existingEntity, $entity, $excludedProperties);
            $entity = $existingEntity;
        } else {
            $entity->setId(null);
        }

        return $entity;
    }

    /**
     * @param Customer $entity
     * @return $this
     */
    protected function validateAndUpdateContext(Customer $entity)
    {
        // validate contact
        $validationErrors = $this->strategyHelper->validateEntity($entity);
        if ($validationErrors) {
            $this->importExportContext->incrementErrorEntriesCount();
            $this->strategyHelper->addValidationErrors($validationErrors, $this->importExportContext);
            return null;
        }

        // increment context counter
        if ($entity->getId()) {
            $this->importExportContext->incrementReplaceCount();
        } else {
            $this->importExportContext->incrementAddCount();
        }

        return $this;
    }

    /**
     * @param mixed $entity
     * @param string $entityIdField
     * @param string $entityClass
     * @return Customer|null
     */
    protected function getEntityOrNull($entity, $entityIdField, $entityClass)
    {
        $existingEntity = null;
        $entityId = $entity->{'get'.ucfirst($entityIdField)}();

        if ($entityId) {
            $existingEntity = $this->getEntityRepository($entityClass)->findOneBy([$entityIdField => $entityId]);
        }

        return $existingEntity ?: null;
    }

    /**
     * @param string $entityName
     * @return EntityRepository
     */
    protected function getEntityRepository($entityName)
    {
        return $this->getEntityManager($entityName)->getRepository($entityName);
    }

    /**
     * @param $entityName
     * @return \Doctrine\ORM\EntityManager
     */
    protected function getEntityManager($entityName)
    {
        return $this->strategyHelper->getEntityManager($entityName);
    }

    /**
     * {@inheritDoc}
     */
    public function setImportExportContext(ContextInterface $importExportContext)
    {
        $this->importExportContext = $importExportContext;
    }


    /**
     * @param AbstractAddress $address
     * @param Customer $entity
     * @param int $mageRegionId
     * @throws \Oro\Bundle\BatchBundle\Item\InvalidItemException
     */
    protected function updateAddressCountryRegion(AbstractAddress $address, Customer $entity, $mageRegionId)
    {
        $countryCode = $address->getCountry()->getIso2Code();

        // country cache
        $this->regionsCache[$countryCode] = empty($this->regionsCache[$countryCode]) ?
            $this->findAndReplaceEntity(
                $address->getCountry(),
                'Oro\Bundle\AddressBundle\Entity\Country',
                'iso2Code',
                ['iso2Code', 'iso3Code', 'name']
            ) :
            $this->regionsCache[$countryCode];

        if (empty($this->mageRegionsCache[$mageRegionId])) {
            $this->mageRegionsCache[$mageRegionId] = $this->getEntityRepository(
                'OroCRM\Bundle\MagentoBundle\Entity\Region'
            )
            ->findOneBy(['region_id' => $mageRegionId]);
        }

        if (empty($this->mageRegionsCache[$mageRegionId])) {
            throw new InvalidItemException(
                sprintf("Cannot find Magento region '%s' by id for '%s' country", $mageRegionId, $countryCode),
                [$entity]
            );
        }

        $mageRegion = $this->mageRegionsCache[$mageRegionId];
        $combinedCode = $mageRegion->getCombinedCode();

        // set ISO combined code
        $address->getRegion()->setCombinedCode($combinedCode);

        // get region
        $this->regionsCache[$combinedCode] = empty($this->regionsCache[$combinedCode]) ?
            $this->getEntityOrNull($address->getRegion(), 'combinedCode', 'Oro\Bundle\AddressBundle\Entity\Region'):
            $this->regionsCache[$combinedCode];

        if (empty($this->regionsCache[$combinedCode])) {
            throw new InvalidItemException(
                sprintf("Cannot find '%s' region for '%s' country", $combinedCode, $countryCode),
                [$entity]
            );
        }

        $address->setCountry($this->regionsCache[$countryCode])
            ->setRegion($this->regionsCache[$combinedCode]);
    }

    /**
     * @param Customer $entity
     * @return $this
     */
    protected function updateAccount(Customer $entity)
    {
        /** @var Account $account */
        $account = $entity->getAccount();
        $account = $this->findAndReplaceEntity(
            $account,
            AccountNormalizer::ACCOUNT_TYPE,
            'name',
            ['id', 'shippingAddress', 'billingAddress']
        );


        // AddressType::TYPE_SHIPPING

        /** @var AbstractAddress $shippingAddress */
        $shippingAddress = $account->getShippingAddress();
        $billingAddress = $account->getBillingAddress();

        $entity->setAccount($account);

        return $this;

    }


    /**
     * @param ContactAddress $address
     * @param int $originId
     */
    protected function createOriginAddressRelation($address, $originId)
    {
        $addressRelation = new AddressRelation();
        $addressRelation->setOriginId($originId)
            ->setAddress($address);

        $this->getEntityManager(self::ADDRESS_RELATION_ENTITY)
            ->persist($addressRelation);
    }

    /**
     * @param int[] $addressIds
     * @return array
     */
    protected function getOriginAddressesIds($addressIds)
    {
        $qb = $this->getEntityRepository(self::ADDRESS_RELATION_ENTITY)
            ->createQueryBuilder('r');

        $result = $qb->where($qb->expr()->in('r.address', $addressIds))
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($result as $item) {
            $items[$item->getOriginId()] = $item->getAddress()->getId();
        }

        return $items;
    }
}
