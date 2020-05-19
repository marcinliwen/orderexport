<?php
/**
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Orderexport extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'orderexport';
        $this->tab = 'analytics_stats';
        $this->version = '1.0.0';
        $this->author = 'MarcinL';
        $this->need_instance = 1;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('orderexport');
        $this->description = $this->l('Eksport zamówień z wyborem przedziału daty');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('ORDEREXPORT_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('ORDEREXPORT_LIVE_MODE');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $output='';
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('generateOrdersCSV')) == true) {
           if ($this->_postValidation()) {
                 $this->postProcess();
            }
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

       
        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'generateOrdersCSV';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Eksport zamówień do pliku CSV'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'date',
                        'label' => $this->l('Zamówienia od:'),
                        'name' => 'order_from',
                        'autoload_rte' => true,
                        'desc' => $this->l('Wybierz datę, od której będą pobrane zamówienia.'),
                    ),
                    array(
                        'type' => 'date',
                        'label' => $this->l('Zamówienia do:'),
                        'name' => 'order_to',
                        'autoload_rte' => true,
                        'desc' => $this->l('Wybierz datę, do będą pobrane zamówienia.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Generuj plik CSV'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'ORDEREXPORT_LIVE_MODE' => Configuration::get('ORDEREXPORT_LIVE_MODE', true),
            'ORDEREXPORT_ACCOUNT_EMAIL' => Configuration::get('ORDEREXPORT_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            'ORDEREXPORT_ACCOUNT_PASSWORD' => Configuration::get('ORDEREXPORT_ACCOUNT_PASSWORD', null),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $from = new DateTime(Tools::getValue('order_from'));
        $to = new DateTime(Tools::getValue('order_to'));

        $sql = 'SELECT od.product_id,od.product_attribute_id,od.id_order,od.product_name,'
        . 'od.product_reference,od.product_ean13,od.product_quantity,o.total_shipping_tax_incl,o.total_paid_tax_excl,'
        . 'o.total_paid_tax_incl,o.date_add,o.payment,osl.name as status,a_f.phone,a_d.postcode,'
        . 'a_d.company,a_d.firstname,a_d.lastname,a_d.address1 as adres_dostawy1,'
        . 'a_d.address2 as adres_dostawy2,a_d.city as adres_dostawy3,a_d.vat_number,'
        . 'a_f.address1 as adres_faktury1,a_f.address2 as adres_faktury2,a_f.city as adres_faktury3,'
        . 'c.firstname as c_firstname,c.lastname as c_lastname,a_d.phone as c_phone, cm.message, '
        . 'car.name as przewoznik, op.payment_method, o.reference as o_reference  '
        . 'FROM ' . _DB_PREFIX_ . 'order_detail od '
        . 'RIGHT JOIN ' . _DB_PREFIX_ . 'orders o ON od.id_order = o.id_order '
        . 'INNER JOIN ' . _DB_PREFIX_ . 'order_state_lang osl ON o.current_state = osl.id_order_state AND osl.id_lang=1 '
        . 'INNER JOIN ' . _DB_PREFIX_ . 'carrier car ON o.id_carrier = car.id_carrier '
        . 'INNER JOIN ' . _DB_PREFIX_ . 'address a_d ON o.id_address_delivery = a_d.id_address '
        . 'INNER JOIN ' . _DB_PREFIX_ . 'address a_f ON o.id_address_invoice = a_f.id_address '
        . 'INNER JOIN ' . _DB_PREFIX_ . 'customer c ON o.id_customer = c.id_customer '
        . 'LEFT JOIN ' . _DB_PREFIX_ . 'order_payment op ON o.reference = op.order_reference '            
        . 'LEFT JOIN ' . _DB_PREFIX_ . 'customer_thread ct ON o.id_order = ct.id_order '
        . 'LEFT JOIN  ( 
                SELECT message, id_customer_thread, MAX( date_add ) AS max_date_add
                FROM ps_customer_message
                WHERE id_employee = 0
                GROUP BY id_customer_thread 
            ) as cm ON ct.id_customer_thread = cm.id_customer_thread 
        WHERE o.date_add > "'.$from->format('Y-m-d H:i:s').'"
        AND o.date_add <"'.$to->format('Y-m-d H:i:s').'"';

            if ($results = Db::getInstance()->ExecuteS($sql)) {

                header("Content-type: text/csv");
                header("Content-Disposition: attachment; filename=orders.csv");
                header("Pragma: no-cache");
                header("Expires: 0");

                echo "Numer zamówienia;Data zamówienia;Status;Kwota zamówienia;Kwota dostawy;Ilość produktów;Miasto\n";
                foreach ($results as $order_detail) {
                    //wiersz danych csv
                echo $order_detail['product_reference'] . ";" . $order_detail['date_add']. ";" . $order_detail['status'] . ";" . $order_detail['total_paid_tax_incl'] . ";" . $order_detail['total_shipping_tax_incl'] . ";" . $order_detail['product_quantity'] . ";" . $order_detail['adres_dostawy3'] ." \n";
                }
            }
            exit();
    }
    protected function _postValidation()
    {
        $errors = array();

        if(!Validate::isDate(Tools::getValue('order_from')) && !Validate::isDate(Tools::getValue('order_to')) || (Tools::getValue('order_from')) >= (Tools::getValue('order_to'))  ){
            $errors[] = $this->getTranslator()->trans('Data "Zamówienie do" musi być większa od daty "Zamówienie od".', array(), 'Modules.Imageslider.Admin');
        }
        if (count($errors)) {
            $this->context->smarty->assign('errors',implode('<br />', $errors) );
            return false;
        }

        /* Returns if validation is ok */

        return true;
    }
    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }
}
