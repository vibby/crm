<?php

namespace OroCRM\Bundle\AccountBundle\Tests\Unit\Form\Type;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use OroCRM\Bundle\AccountBundle\Form\Type\AccountType;

class AccountTypeTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var AccountType
     */
    protected $type;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $router;

    protected function setUp()
    {
        $flexibleManager = $this->getMockBuilder('Oro\Bundle\FlexibleEntityBundle\Manager\FlexibleManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->router = $this->getMockBuilder('Symfony\Component\Routing\Router')
            ->disableOriginalConstructor()
            ->getMock();

        $this->type = new AccountType($flexibleManager, 'orocrm_account', $this->router);
    }

    public function testAddEntityFields()
    {
        $builder = $this->getMockBuilder('Symfony\Component\Form\FormBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $builder->expects($this->any(3))
            ->method('add')
            ->will($this->returnSelf());

        $builder->expects($this->at(1))
            ->method('add')
            ->with('name', 'text')
            ->will($this->returnSelf());
        $builder->expects($this->at(2))
            ->method('add')
            ->with('tags', 'oro_tag_select')
            ->will($this->returnSelf());
        $builder->expects($this->at(3))
            ->method('add')
            ->with('default_contact', 'oro_entity_identifier')
            ->will($this->returnSelf());

        $defaultContactType = $this->getMockBuilder('Symfony\Component\Form\FormBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $defaultContactType->expects($this->once())
            ->method('getForm');
        $builder->expects($this->at(4))
            ->method('get')
            ->with('default_contact')
            ->will($this->returnValue($defaultContactType));
        $builder->expects($this->at(5))
            ->method('add')
            ->with('contacts', 'oro_multiple_entity')
            ->will($this->returnSelf());
        $builder->expects($this->at(6))
            ->method('add')
            ->with('shippingAddress', 'oro_address')
            ->will($this->returnSelf());
        $builder->expects($this->at(7))
            ->method('add')
            ->with('billingAddress', 'oro_address')
            ->will($this->returnSelf());

        $this->type->addEntityFields($builder);
    }

    public function testAddDynamicAttributesFields()
    {
        /** @var FormBuilderInterface $builder */
        $builder = $this->getMockBuilder('Symfony\Component\Form\FormBuilder')
            ->disableOriginalConstructor()
            ->getMock();

        $builder->expects($this->once())
            ->method('add')
            ->with('values', 'collection');
        $this->type->addDynamicAttributesFields($builder, array());
    }

    public function testSetDefaultOptions()
    {
        /** @var OptionsResolverInterface $resolver */
        $resolver = $this->getMock('Symfony\Component\OptionsResolver\OptionsResolverInterface');
        $resolver->expects($this->once())
            ->method('setDefaults')
            ->with($this->isType('array'));
        $this->type->setDefaultOptions($resolver);
    }

    public function testGetName()
    {
        $this->assertEquals('orocrm_account', $this->type->getName());
    }

    public function testFinishView()
    {
        $this->router->expects($this->at(0))
            ->method('generate')
            ->with('orocrm_account_contact_select', array('id' => 100))
            ->will($this->returnValue('/test-path/100'));
        $this->router->expects($this->at(1))
            ->method('generate')
            ->with('orocrm_contact_info', array('id' => 1))
            ->will($this->returnValue('/test-info/1'));

        $contact = $this->getMockBuilder('OroCRM\Bundle\ContactBundle\Entity\Contact')
            ->disableOriginalConstructor()
            ->getMock();
        $contact->expects($this->exactly(2))
            ->method('getId')
            ->will($this->returnValue(1));
        $contact->expects($this->once())
            ->method('getFirstName')
            ->will($this->returnValue('John'));
        $contact->expects($this->once())
            ->method('getLastName')
            ->will($this->returnValue('Doe'));
        $contacts = new ArrayCollection(array($contact));

        $account = $this->getMockBuilder('OroCRM\Bundle\AccountBundle\Entity\Account')
            ->disableOriginalConstructor()
            ->getMock();
        $account->expects($this->once())
            ->method('getId')
            ->will($this->returnValue(100));
        $account->expects($this->once())
            ->method('getContacts')
            ->will($this->returnValue($contacts));
        $form = $this->getMockBuilder('Symfony\Component\Form\Form')
            ->disableOriginalConstructor()
            ->getMock();
        $form->expects($this->exactly(2))
            ->method('getData')
            ->will($this->returnValue($account));

        $formView = new FormView();
        $contactsFormView = new FormView($formView);
        $formView->children['contacts'] = $contactsFormView;
        $this->type->finishView($formView, $form, array());

        $this->assertEquals($contactsFormView->vars['grid_url'], '/test-path/100');
        $expectedInitialElements = array(
            array(
                'id' => 1,
                'label' => 'John Doe',
                'link' => '/test-info/1'
            )
        );
        $this->assertEquals($expectedInitialElements, $contactsFormView->vars['initial_elements']);
    }
}
