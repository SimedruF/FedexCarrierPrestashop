<?php
/**
 * 2024 Automatic House Systems
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @author    Florin Simedru <simedruflorin@automatic-house.ro>
 * @copyright 2024 Automatic House Systems
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FedexCarrier extends CarrierModule
{
    protected $config_form = false;
    
    // API credentials
    private $fedex_key;
    private $fedex_password;
    private $fedex_account;
    private $fedex_meter;
    private $fedex_test_mode;
    
    // Carrier settings
    private $carrier_id;
   
    public function __construct()
    {
        $this->name = 'fedexcarrier';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Florin Simedru';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fedex Carrier');
        $this->description = $this->l('Integrate Fedex shipping services with your store');

        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '8.99.99');
        
        // Load config values
        $this->fedex_key = Configuration::get('FEDEX_KEY');
        $this->fedex_password = Configuration::get('FEDEX_PASSWORD');
        $this->fedex_account = Configuration::get('FEDEX_ACCOUNT');
        $this->fedex_meter = Configuration::get('FEDEX_METER');
        $this->fedex_test_mode = Configuration::get('FEDEX_TEST_MODE');
        $this->carrier_id = (int)Configuration::get('FEDEX_CARRIER_ID');
    }

    /**
     * Install the module and register hooks
     */
    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('displayBackOfficeHeader') || 
            !$this->registerHook('actionCarrierProcess') ||
            !$this->registerHook('updateCarrier')) {
            return false;
        }

        // Create the carrier
        if (!$this->createCarrier()) {
            return false;
        }

        return true;
    }

    /**
     * Uninstall the module
     */
    public function uninstall()
    {
        if (!Configuration::deleteByName('FEDEX_KEY') ||
            !Configuration::deleteByName('FEDEX_PASSWORD') ||
            !Configuration::deleteByName('FEDEX_ACCOUNT') ||
            !Configuration::deleteByName('FEDEX_METER') ||
            !Configuration::deleteByName('FEDEX_TEST_MODE') ||
            !Configuration::deleteByName('FEDEX_CARRIER_ID') ||
            !parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * Create a new carrier
     */
    protected function createCarrier()
    {
        $carrier = new Carrier();
        
        $carrier->name = 'Fedex';
        $carrier->url = 'https://www.fedex.com/fedextrack';
        $carrier->active = true;
        $carrier->deleted = 0;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        
        // Setează delay pentru toate limbile active
        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            $carrier->delay[$language['id_lang']] = 'Deliver with Fedex';
        }
        
        $carrier->shipping_external = true;
        $carrier->is_module = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;
        
        // Setează explicit metoda de expediere și alte proprietăți necesare
        $carrier->shipping_method = 1; // 1 pentru greutate, 2 pentru preț
        $carrier->max_weight = 30.000;
        $carrier->weight = 0;
        $carrier->grade = 9;
        
        if ($carrier->add()) {
            // Salvează ID-ul transportatorului
            Configuration::updateValue('FEDEX_CARRIER_ID', (int)$carrier->id);
            
            // Setează zonele pentru transportator - important pentru vizibilitate!
            $zones = Zone::getZones(true);
            foreach ($zones as $zone) {
                $carrier->addZone((int)$zone['id_zone']);
            }
            
            // Adaugă intervalele de tarifare pentru transportator
            $range_weight = new RangeWeight();
            $range_weight->id_carrier = $carrier->id;
            $range_weight->delimiter1 = 0;
            $range_weight->delimiter2 = 30;
            $range_weight->add();
            
            // Adaugă prețuri pentru fiecare zonă
            foreach ($zones as $zone) {
                Db::getInstance()->insert('delivery', array(
                    'id_carrier' => (int)$carrier->id,
                    'id_range_weight' => (int)$range_weight->id,
                    'id_range_price' => 0,
                    'id_zone' => (int)$zone['id_zone'],
                    'price' => 10.0
                ));
            }
            
            // Setează grupurile pentru transportator
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group', array(
                    'id_carrier' => (int)$carrier->id,
                    'id_group' => (int)$group['id_group']
                ));
            }
            
            // Setează logo-ul transportatorului
            if (!copy(dirname(__FILE__).'/views/img/fedex.jpg', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg')) {
                // Dacă există o eroare la copierea logo-ului, continuăm
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Add carrier weight ranges
     */
    protected function addCarrierRanges($carrier)
    {
        $range_weight = new RangeWeight();
        $range_weight->id_carrier = $carrier->id;
        $range_weight->delimiter1 = 0;
        $range_weight->delimiter2 = 10;
        $range_weight->add();
    }
    
    /**
     * Add carrier to all customer groups
     */
    protected function addCarrierGroups($carrier)
    {
        $groups = Group::getGroups(true);
        foreach ($groups as $group) {
            Db::getInstance()->insert(
                'carrier_group',
                array(
                    'id_carrier' => (int)$carrier->id,
                    'id_group' => (int)$group['id_group']
                )
            );
        }
    }
    
    /**
     * Add carrier to all zones
     */
    protected function addCarrierZones($carrier)
    {
        $zones = Zone::getZones(true);
        foreach ($zones as $zone) {
            Db::getInstance()->insert(
                'carrier_zone',
                array(
                    'id_carrier' => (int)$carrier->id,
                    'id_zone' => (int)$zone['id_zone']
                )
            );
        }
    }
    
    /**
     * Add carrier logo
     */
    protected function addCarrierLogo($carrier)
    {
        // Copy logo
        if (!copy(dirname(__FILE__).'/views/img/fedex.webp', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.webp')) {
            // If there's an error copying the logo, we don't want to stop the install process
            return false;
        }
        
        return true;
    }

    /**
     * Back Office configuration page
     */
    public function getContent()
    {
        $output = '';
        
        // Process form submission
        if (Tools::isSubmit('submit'.$this->name)) {
            $fedex_key = Tools::getValue('FEDEX_KEY');
            $fedex_password = Tools::getValue('FEDEX_PASSWORD');
            $fedex_account = Tools::getValue('FEDEX_ACCOUNT');
            $fedex_meter = Tools::getValue('FEDEX_METER');
            $fedex_test_mode = Tools::getValue('FEDEX_TEST_MODE');
            
            Configuration::updateValue('FEDEX_KEY', $fedex_key);
            Configuration::updateValue('FEDEX_PASSWORD', $fedex_password);
            Configuration::updateValue('FEDEX_ACCOUNT', $fedex_account);
            Configuration::updateValue('FEDEX_METER', $fedex_meter);
            Configuration::updateValue('FEDEX_TEST_MODE', $fedex_test_mode);
            
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        
        // Show configuration form
        return $output.$this->renderForm();
    }

    /**
     * Create the configuration form
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
        $helper->submit_action = 'submit'.$this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
        
        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create configuration form structure
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Fedex API Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'name' => 'FEDEX_KEY',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Password'),
                        'name' => 'FEDEX_PASSWORD',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Account Number'),
                        'name' => 'FEDEX_ACCOUNT',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Meter Number'),
                        'name' => 'FEDEX_METER',
                        'required' => true,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Test Mode'),
                        'name' => 'FEDEX_TEST_MODE',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No'),
                            ),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the configuration form
     */
    protected function getConfigFormValues()
    {
        return array(
            'FEDEX_KEY' => Configuration::get('FEDEX_KEY'),
            'FEDEX_PASSWORD' => Configuration::get('FEDEX_PASSWORD'),
            'FEDEX_ACCOUNT' => Configuration::get('FEDEX_ACCOUNT'),
            'FEDEX_METER' => Configuration::get('FEDEX_METER'),
            'FEDEX_TEST_MODE' => Configuration::get('FEDEX_TEST_MODE'),
        );
    }

    /**
     * Calculate shipping cost
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        // If module not configured, return default cost
        if (empty($this->fedex_key) || empty($this->fedex_password) || 
            empty($this->fedex_account) || empty($this->fedex_meter)) {
            return $shipping_cost;
        }
        
        // Get cart details
        $address = new Address($params->id_address_delivery);
        $country = new Country($address->id_country);
        
        // Calculate total weight
        $weight = $this->getCartTotalWeight($params);
        
        // Get shipping cost from Fedex API
        $cost = $this->getFedexShippingRate($address, $country, $weight);
        
        if ($cost !== false) {
            return $cost;
        }
        
        return $shipping_cost;
    }
    
    /**
     * Calculate cart total weight
     */
    protected function getCartTotalWeight($cart)
    {
        $total_weight = 0;
        $products = $cart->getProducts();
        
        foreach ($products as $product) {
            $total_weight += ($product['weight'] * $product['cart_quantity']);
        }
        
        return $total_weight;
    }
    
    /**
     * Get shipping rates from Fedex API
     */
    protected function getFedexShippingRate($address, $country, $weight)
    {
        // In a real implementation, you would build an XML request to the Fedex API
        // Here's a simplified example that returns a fixed rate
        
        // This is where you would normally make an API call to Fedex
        // For now, we're simulating the response
        
        // Base rate for domestic shipping
        $base_rate = 10.0;
        
        // Add weight surcharge (example: $1 per kg)
        $weight_surcharge = $weight * 1.0;
        
        // Add distance surcharge
        $distance_surcharge = ($country->iso_code == 'RO') ? 0 : 15.0;
        
        // Calculate total shipping cost
        $total_cost = $base_rate + $weight_surcharge + $distance_surcharge;
        
        return $total_cost;
    }
    
    /**
     * Hook to update carrier ID when carrier is updated
     */
    public function hookUpdateCarrier($params)
    {
        $id_carrier_old = (int)$params['id_carrier'];
        $id_carrier_new = (int)$params['carrier']->id;
        
        if ($id_carrier_old == Configuration::get('FEDEX_CARRIER_ID')) {
            Configuration::updateValue('FEDEX_CARRIER_ID', $id_carrier_new);
        }
    }
    /**
     * Calculate shipping cost from external shipping api
     */
    public function getOrderShippingCostExternal($params)
    {
        // Get cart details
        $address = new Address($params->id_address_delivery);
        $country = new Country($address->id_country);
        
        // Calculate total weight
        $weight = $this->getCartTotalWeight($params);
        
        // Get shipping cost from Fedex API
        $cost = $this->getFedexShippingRate($address, $country, $weight);
        
        if ($cost !== false) {
            return $cost;
        }
        
        return false;
    }
    /**
     * Add JS/CSS in back office header
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            // Adaugă CSS și/sau JavaScript pentru pagina de configurare a modulului
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }
    /**
     * Hook called during the carrier process
     */
    public function hookActionCarrierProcess($params)
    {
        // Aici poți adăuga logică specifică pentru când clientul selectează acest transportator
        // De exemplu, poți verifica dacă transportatorul selectat este Fedex
        if ($params['carrier']->id == $this->carrier_id) {
            // Logică pentru procesarea transportatorului Fedex
            // Exemplu: poți salva detalii suplimentare în sesiune sau baza de date
            $this->context->cookie->fedex_selected = true;
        }
        
        return true;
    }
}

