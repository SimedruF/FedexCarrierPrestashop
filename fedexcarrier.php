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
            !$this->registerHook('actionCarrierUpdate') || 
            !$this->registerHook('displayCarrierExtraContent') ||
            !$this->registerHook('displayBackOfficeHeader') || 
            !$this->registerHook('actionCarrierProcess') ||
            !$this->registerHook('updateCarrier') ||
            !$this->registerHook('displayBeforeCarrier')
           ) {
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
        
        $carrier->name = 'FedEx Carrier';
        $carrier->url = 'https://www.fedex.com/fedextrack';
        $carrier->active = true;
        $carrier->deleted = 0;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        
        // Set delay for all active languages
        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            $carrier->delay[$language['id_lang']] = 'Deliver with Fedex';
        }
        
        $carrier->shipping_external = true;
        $carrier->is_module = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;
        
        // Explicitly set the shipping method and other necessary properties
        $carrier->shipping_method = 1; // 1 for weight, 2 for price
        $carrier->max_weight = 30.000;
        $carrier->weight = 0;
        $carrier->grade = 9;
        
        if ($carrier->add()) {
            // Save the carrier ID
            Configuration::updateValue('FEDEX_CARRIER_ID', (int)$carrier->id);
            
            // Set zones for the carrier - important for visibility!
            $zones = Zone::getZones(true);
            foreach ($zones as $zone) {
                $carrier->addZone((int)$zone['id_zone']);
            }
            
            // Add weight ranges for the carrier
            $range_weight = new RangeWeight();
            $range_weight->id_carrier = $carrier->id;
            $range_weight->delimiter1 = 0;
            $range_weight->delimiter2 = 30;
            $range_weight->add();
            
            // Add prices for each zone
            foreach ($zones as $zone) {
                Db::getInstance()->insert('delivery', array(
                    'id_carrier' => (int)$carrier->id,
                    'id_range_weight' => (int)$range_weight->id,
                    'id_range_price' => 0,
                    'id_zone' => (int)$zone['id_zone'],
                    'price' => 10.0
                ));
            }
            
            // Set groups for the carrier
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group', array(
                    'id_carrier' => (int)$carrier->id,
                    'id_group' => (int)$group['id_group']
                ));
            }
            
            // Set the carrier logo
            if (!copy(dirname(__FILE__).'/views/img/fedex.jpg', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg')) {
                // If there's an error copying the logo, continue
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
        
        $output .= $this->getContentTest();
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
        $fedex_test_mode = (bool)Configuration::get('FEDEX_TEST_MODE');

        if ($fedex_test_mode) {
            // Return simulated data for Test Mode
            $fedex_response =  $this->getDefaultFedexTestResponse();
            return $this->getFedexShippingRateTotal($country, $weight);
        }

        $accountNumber = $this->fedex_account;
        $endpoint = $this->fedex_test_mode
            ? 'https://apis-sandbox.fedex.com/rate/v1/rates/quotes'
            : 'https://apis.fedex.com/rate/v1/rates/quotes';
    
        $accessToken = $this->getFedexAccessToken();
        if (!$accessToken) {
            return false; // Could not obtain the token
        }
    
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ];
    
        $body = [
            "accountNumber" => [
                "value" => $accountNumber
            ],
            "requestedShipment" => [
                "shipper" => [
                    "address" => [
                        "postalCode" => '10000',   // <-- sender postal code (you choose a fixed one or from config)
                        "countryCode" => 'RO'       // <-- sender country
                    ]
                ],
                "recipient" => [
                    "address" => [
                        "postalCode" => $address,     // <-- recipient postal code
                        "countryCode" => $country     // <-- recipient country
                    ]
                ],
                "pickupType" => "DROPOFF_AT_FEDEX_LOCATION",
                "rateRequestType" => ["ACCOUNT"],
                "requestedPackageLineItems" => [
                    [
                        "weight" => [
                            "units" => "KG",
                            "value" => (float) $weight
                        ]
                    ]
                ]
            ]
        ];
    
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode == 200) {
            $data = json_decode($response, true);
            if (isset($data['output']['rateReplyDetails'][0]['ratedShipmentDetails'][0]['totalNetChargeWithDutiesAndTaxes']['amount'])) {
                return (float) $data['output']['rateReplyDetails'][0]['ratedShipmentDetails'][0]['totalNetChargeWithDutiesAndTaxes']['amount'];
            }
        }
    
        return false;
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
    private function getFedexAccessToken()
    {
        $fedex_test_mode = (bool)Configuration::get('FEDEX_TEST_MODE');

        // Dacă Test Mode este activat, returnează un token simulant
        if ($fedex_test_mode) {
            return $this->getDefaultFedexAccessToken();
        }

        $clientId = $this->fedex_key;         // API Key
        $clientSecret = $this->fedex_password; // API Password
        $authUrl = $this->fedex_test_mode
            ? 'https://apis-sandbox.fedex.com/oauth/token' // Sandbox URL
            : 'https://apis.fedex.com/oauth/token';        // Production URL
    
        $headers = [
            'Content-Type: application/x-www-form-urlencoded'
        ];
    
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ]);
    
        $ch = curl_init($authUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($httpcode == 200) {
            
            if (isset($data['access_token'])) {
                return $data['access_token'];
            }
        }
        else {
            // echo '<pre>OAuth Error: ';
            // print_r($data);
            // echo '</pre>';
            // die();
        }
    
        return false;
    }
    protected function getFedexShippingRateTotal( $country, $weight)
    {
        // In a real implementation, you would build an XML request to the Fedex API
        // Here's a simplified example that returns a fixed rate
        
        // This is where you would normally make an API call to Fedex
        // For now, we're simulating the response
        
        // Base rate for domestic shipping
        $base_rate = 10.0;
        
        // Add weight surcharge (example: $1 per kg)
        $weight_surcharge = (float)$weight * 1.0;
        
        // Add distance surcharge
        $distance_surcharge = ($country->iso_code == 'RO') ? 0 : 15.0;
        
        // Calculate total shipping cost
        $total_cost = $base_rate + $weight_surcharge + $distance_surcharge;
        
        return $total_cost;
    }
    protected function installCarrier()
    {
        $carrier = new Carrier();
        $carrier->name = 'FedEx Carrier'; // Numele care apare în Admin
        $carrier->id_tax_rules_group = 0; // Fără reguli de taxe
        $carrier->active = 1; // Activ
        $carrier->deleted = 0; // Nu este șters
        $carrier->shipping_handling = false; // Nu aplică handling cost
        $carrier->range_behavior = 0;
        $carrier->is_module = true; // Este creat de un modul
        $carrier->external_module_name = $this->name; // Numele modulului
        $carrier->need_range = true; // Are nevoie de range (min weight / max weight)
        $carrier->shipping_external = true; // Transport extern
        $carrier->url = 'https://www.fedex.com/fedextrack/?tracknumbers=@'; // URL de tracking

        // Setează explicit metoda de expediere și alte proprietăți necesare
        $carrier->shipping_method = 1; // 1 pentru greutate, 2 pentru preț
        $carrier->max_weight = 68.000;
        $carrier->weight = 0;
        $carrier->grade = 9;

        // Setez delay-ul pentru fiecare limbă
        foreach (Language::getLanguages(true) as $language) {
            $carrier->delay[(int) $language['id_lang']] = 'Livrare prin FedEx';
        }

        if (!$carrier->add()) {
            return false;
        }

        // Salvez ID-ul Carrier creat
        Configuration::updateValue('FEDEX_CARRIER_ID', (int) $carrier->id);

        // Asociez Carrier-ul cu toate grupurile de utilizatori
        $groups = Group::getGroups(true);
        foreach ($groups as $group) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int) $carrier->id,
                'id_group' => (int) $group['id_group']
            ]);
        }

        // Asociez Carrier-ul cu toate zonele
        $zones = Zone::getZones(true);
        foreach ($zones as $zone) {
            Db::getInstance()->insert('carrier_zone', [
                'id_carrier' => (int) $carrier->id,
                'id_zone' => (int) $zone['id_zone']
            ]);
        }

        // Asociez Carrier-ul cu toate magazinele (pentru multistore)
        $shops = Shop::getShops(true);
        foreach ($shops as $shop) {
            Db::getInstance()->insert('carrier_shop', [
                'id_carrier' => (int) $carrier->id,
                'id_shop' => (int) $shop['id_shop']
            ]);
        }

        // Creez range-uri de greutate default (0kg - 1000kg)
        $rangeWeight = new RangeWeight();
        $rangeWeight->id_carrier = (int) $carrier->id;
        $rangeWeight->delimiter1 = 0;
        $rangeWeight->delimiter2 = 1000;
        $rangeWeight->add();

        // Setează logo-ul transportatorului
        if (!copy(dirname(__FILE__).'/views/img/fedex.jpg', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg')) {
                // Dacă există o eroare la copierea logo-ului, continuăm
        }

        return true;
    }
    public function hookDisplayCarrierExtraContent($params)
    {
        return ''; // Momentan nu afișăm nimic
    }
    public function testCreateCarrier()
    {
        if ($this->createCarrier()) {
            return $this->displayConfirmation('Carrier created successfully. ID: ' . Configuration::get('FEDEX_CARRIER_ID'));
        } else {
            return $this->displayError('Failed to create carrier.');
        }
    }

    // Modifică getContent() pentru a adăuga un buton de test
    public function getContentTest()
    {
        $output = '';
        
        // Apelăm verificarea carrierului FedEx
        $output .= $this->checkFedexCarrier();

        // Test carrier creation
        if (Tools::isSubmit('testCreateCarrier')) {
            $output .= $this->testCreateCarrier();
        }
        
        // Process form submission
        if (Tools::isSubmit('submit'.$this->name)) {
            // existing code...
        }
        
        // Adaugă butonul de test
        $output .= '<br> <div class="panel">
            <div class="panel-heading">Test Functions</div>
            <form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post">
                <button type="submit" name="testCreateCarrier" class="btn btn-default">
                    Test Create Carrier
                </button>
            </form>
        </div>';
        

        if (Tools::isSubmit('check_fedex')) {
            $output .= $this->diagnoseFedexCarrier((int)Tools::getValue('id_customer'));
        }
    
        $output .= '
        <br>
        <div class="panel">
            <form method="post">
                <input type="hidden" name="check_fedex" value="1">
                <label for="id_customer">ID Client:</label>
                <input type="text" name="id_customer" id="id_customer" required>
                <button type="submit" class="btn btn-primary">Verifică FedEx pentru client</button>
            </form>
            </div>
        ';

        // Verificăm dacă utilizatorul a apăsat butonul pentru testarea API-ului
        if (Tools::isSubmit('check_fedex_api')) {
            // Îl vom apela pe funcția care efectuează testul API-ului FedEx
            $output .= $this->testFedexAPI();
        }

        // Formularul pentru a testa API-ul FedEx
        $output .= '<br>
        <div class="panel">
            <form method="post">
                <input type="hidden" name="check_fedex_api" value="1">
                <h3>Testați apelul API FedEx</h3>
                <button type="submit" class="btn btn-primary">Verificați API-ul FedEx</button>
            </form>
           </div>  
        ';
        // Show configuration form
        return $output;
    }
    protected function checkFedexCarrier()
    {
        $carrierName = 'FedEx'; // Numele carrier-ului tău
        $carrier = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'carrier 
            WHERE name LIKE "%'.$carrierName.'%" AND deleted = 0
        ');
    
        if (!$carrier) {
            return '<div class="alert alert-danger">❌ Carrier-ul FedEx nu există în baza de date sau este șters!</div>';
        }
    
        $carrierId = (int)$carrier['id_carrier'];
        $messages = [];
    
        // Verificare active
        if ((int)$carrier['active'] !== 1) {
            $messages[] = '⚠️ Carrier-ul există, dar NU este activ!';
        } else {
            $messages[] = '✅ Carrier-ul este activ.';
        }
    
        // Verificare legătura cu magazinul
        $shopLink = Db::getInstance()->getRow("
            SELECT * FROM "._DB_PREFIX_."carrier_shop 
            WHERE id_carrier = ".$carrierId);
        if (!$shopLink) {
            $messages[] = '⚠️ Carrier-ul nu este asociat cu niciun magazin!';
        } else {
            $messages[] = '✅ Carrier-ul este asociat cu magazinul.';
        }
    
        // Verificare legătura cu zone
        $zoneLink = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'carrier_zone 
            WHERE id_carrier = '.$carrierId);
        if (!$zoneLink) {
            $messages[] = '⚠️ Carrier-ul nu este asociat cu nicio zonă!';
        } else {
            $messages[] = '✅ Carrier-ul este asociat cu cel puțin o zonă.';
        }
    
        // Verificare legătura cu grupuri
        $groupLink = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'carrier_group 
            WHERE id_carrier = '.$carrierId);
        if (!$groupLink) {
            $messages[] = '⚠️ Carrier-ul nu este asociat cu niciun grup de utilizatori!';
        } else {
            $messages[] = '✅ Carrier-ul este asociat cu grupuri de utilizatori.';
        }
    
        // Combină toate mesajele
        return '<div class="alert alert-info"><strong>Verificare FedEx Carrier:</strong><ul><li>' 
                . implode('</li><li>', $messages) . 
               '</li></ul></div>';
    }
    protected function diagnoseFedexCarrier($id_customer)
    {
        $output = '';
        $carrierName = 'FedEx';

        // Găsim carrier-ul FedEx
        $carrier = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'carrier 
            WHERE name LIKE "%'.$carrierName.'%" AND deleted = 0');

        if (!$carrier) {
            return '<div class="alert alert-danger">❌ Carrier-ul FedEx nu există!</div>';
        }

        $id_carrier = (int)$carrier['id_carrier'];
        $output .= "<h3>✅ Carrier FedEx găsit (ID: $id_carrier)</h3>";

        // Găsim clientul
        $customer = new Customer($id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return '<div class="alert alert-danger">❌ Clientul nu există!</div>';
        }

        $output .= '<p>Client: '.$customer->firstname.' '.$customer->lastname.'</p>';

        // Găsim adresa principală
        $id_address = (int)Address::getFirstCustomerAddressId($id_customer);
        if (!$id_address) {
            return '<div class="alert alert-danger">❌ Clientul nu are adresă salvată!</div>';
        }

        $address = new Address($id_address);
        $id_country = (int)$address->id_country;
        $id_state = (int)$address->id_state;
        $id_zone = Address::getZoneById($id_address);

        $output .= '<p>Adresa clientului: '.$address->address1.', '.$address->city.'</p>';

        // Verificăm zona
        $zone = new Zone($id_zone);
        if (!Validate::isLoadedObject($zone)) {
            $output .= '<div class="alert alert-danger">❌ Zona adresei clientului nu există!</div>';
        } else {
            $output .= '<p>✅ Zona clientului: '.$zone->name.'</p>';
        }

        // Verificăm dacă carrier-ul e asociat cu zona
        $zoneLink = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'carrier_zone WHERE id_carrier = '.$id_carrier.' AND id_zone = '.$id_zone);
        if (!$zoneLink) {
            $output .= '<div class="alert alert-danger">❌ Carrier-ul FedEx NU este asociat cu zona clientului ('.$zone->name.')!</div>';
        } else {
            $output .= '<p>✅ Carrier-ul este asociat cu zona clientului.</p>';
        }

        // Verificăm dacă există un range de greutate
        $range = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'range_weight WHERE id_carrier = '.$id_carrier);
        if (!$range) {
            $output .= '<div class="alert alert-danger">❌ Carrier-ul NU are definit un range de greutate!</div>';
        } else {
            $output .= '<p>✅ Carrier-ul are range de greutate: '.$range['delimiter1'].'kg - '.$range['delimiter2'].'kg</p>';
        }

        // Verificăm dacă există tarife în ps_delivery
        $delivery = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'delivery WHERE id_carrier = '.$id_carrier.' AND id_zone = '.$id_zone);
        if (!$delivery) {
            $output .= '<div class="alert alert-danger">❌ Nu există tarife de livrare definite pentru zona clientului!</div>';
        } else {
            $output .= '<p>✅ Tarife de livrare existente pentru zona clientului.</p>';
        }

        // Verificăm delay-ul (descrierea livrării)
        $langId = (int)Configuration::get('PS_LANG_DEFAULT');
        $carrierLang = Db::getInstance()->getRow('
            SELECT * FROM '._DB_PREFIX_.'carrier_lang WHERE id_carrier = '.$id_carrier.' AND id_lang = '.$langId);
        if (empty($carrierLang['delay'])) {
            $output .= '<div class="alert alert-danger">❌ Nu există delay (timp de livrare) definit pentru carrier!</div>';
        } else {
            $output .= '<p>✅ Delay setat: '.$carrierLang['delay'].'</p>';
        }

        // Verificăm grupurile de clienți
        $groups = Customer::getGroupsStatic($id_customer);
        $group_ok = false;
        foreach ($groups as $group) {
            $groupLink = Db::getInstance()->getRow('
                SELECT * FROM '._DB_PREFIX_.'carrier_group 
                WHERE id_carrier = '.$id_carrier.' AND id_group = '.(int)$group);
            if ($groupLink) {
                $group_ok = true;
                break;
            }
        }
        if (!$group_ok) {
            $output .= '<div class="alert alert-danger">❌ Carrier-ul FedEx nu este asociat cu grupul clientului!</div>';
        } else {
            $output .= '<p>✅ Carrier-ul este asociat cu grupul clientului.</p>';
        }

        return '<div class="alert alert-info">'.$output.'</div>';
    }
    // Funcția care efectuează apelul la API-ul FedEx și returnează rezultatul
    public function testFedexAPI()
    {
        // Get cart details
        //$address = new Address($params->id_address_delivery);
        $country = new Country(3);

        // Setează variabilele de configurare pentru accesul la API
        $fedex_key = Configuration::get('FEDEX_KEY');
        $fedex_password = Configuration::get('FEDEX_PASSWORD');
        $fedex_account = Configuration::get('FEDEX_ACCOUNT');
        $fedex_meter = Configuration::get('FEDEX_METER');
        $fedex_test_mode = (bool)Configuration::get('FEDEX_TEST_MODE');

        // URL-ul API-ului (sandbox sau live, în funcție de setări)
        $url = $fedex_test_mode
            ? 'https://apis-sandbox.fedex.com/rate/v1/rates/quotes'
            : 'https://apis.fedex.com/rate/v1/rates/quotes';

        // Obține token-ul de acces
        $token = $this->getFedexAccessToken($fedex_key, $fedex_password, $fedex_test_mode);

        // Trimite cererea către API-ul FedEx pentru calculul tarifelor
        $response = $this->getFedexShippingRate($token,$country,"");

        // Întoarcerea rezultatului API
        if ($response) {
            return '<pre>' . print_r($response, true) . '</pre>';
        } else {
            return '<p>Eroare la apelul API FedEx. Vă rugăm să verificați setările API.</p>';
        }
    }
    // Funcția care returnează un răspuns simulant pentru Test Mode
    private function getDefaultFedexTestResponse()
    {
        // Date simulate ca un răspuns de la API-ul FedEx
        return [
            "rateReply" => [
                "rateReplyDetails" => [
                    [
                        "serviceType" => "FEDEX_GROUND",
                        "deliveryTimestamp" => "2025-04-27T12:00:00-07:00",
                        "totalNetCharge" => [
                            "currency" => "RON",
                            "amount" => "15.00"
                        ],
                        "rateAsString" => "15.00",
                        "billingWeight" => [
                            "units" => "KG",
                            "value" => 2
                        ],
                        "weightUnits" => "KG",
                        "weight" => 2
                    ]
                ]
            ]
        ];
    }
    // Funcția care returnează un access token simulant pentru Test Mode
    private function getDefaultFedexAccessToken()
    {
        // Răspuns simulant pentru Test Mode - un token de acces
        return 'default_test_access_token_12345';
    }
}

