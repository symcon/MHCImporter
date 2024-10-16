<?php

declare(strict_types=1);
    class MyHomeControlConfigurator extends IPSModule
    {
        public const GUID_MAP = [
            'PTMSwitchModule' => '{63484585-F8AD-4780-BAFD-3C0353641046}',
            'Switch_1' => '{FD46DA33-724B-489E-A931-C00BFD0166C9}',
            'Dimmer_1' => '{48909406-A2B9-4990-934F-28B9A80CD079}',
            'Jalousie_1' =>  '{1463CAE7-C7D5-4623-8539-DD7ADA6E92A9}',
        ];
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('ImportFile', '');
        }

        public function Destroy()
        {
            //Never delete this line!
            parent::Destroy();
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();
            if ($this->UIValidateImport($this->ReadPropertyString('ImportFile'))) {
                $this->UIImport($this->ReadPropertyString('ImportFile'));
            }
        }

        public function GetConfigurationForm(): string
        {
            $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            if ($this->UIValidateImport($this->ReadPropertyString('ImportFile'))) {
                $data['actions'][0]['values'] = $this->createConfiguratorValues($this->ReadPropertyString('ImportFile'));
            }
            return json_encode($data);
        }

        public function UIValidateImport($File)
        {
            if (strlen($File) == 0) {
                return false;
            }
            $xml = simplexml_load_string(base64_decode($File), null, LIBXML_NOCDATA);
            $array = json_decode(json_encode($xml), true);
            $this->SendDebug('Validate', json_encode($array), 0);
            if (!array_key_exists('DeviceAddresses', $array)) {
                $this->SendDebug('No Address', json_encode($array['DeviceAddresses']), 0);
                return false;
            }
            return true;
        }

        private function createConfiguratorValues(String $File)
        {
            $xml = simplexml_load_string(base64_decode($File), null, LIBXML_NOCDATA);
            $array = json_decode(json_encode($xml), true);
            $configurator = [];
            foreach ($array['DeviceAddresses']['Sensor'] as $sensor) {
                $this->createDevice($configurator, $sensor['@attributes'], 'Sensor');
            }
            foreach ($array['DeviceAddresses']['Actuator'] as $actuator) {
                $this->createDevice($configurator, $actuator['@attributes'], 'Actuator');
            }
            return $configurator;
        }

        public function UIImport($File)
        {
            $this->UpdateFormField('Configurator', 'values', json_encode($this->createConfiguratorValues($File)));
        }

        private function createDevice(&$configurator, $attributes, String $type)
        {
            $path = explode('/', $attributes['Ref']);
            // We only want to add the "cateogries" and not the device itself
            for ($i=0; $i < count($path) -1 ; $i++) {
                if (!$this->hasID($path[$i], $configurator)) {
                    $node = [
                        'name' => $path[$i],
                        // TODO: We should use the path until here as id to prevent errors by duplicate names
                        'id' => $path[$i],
                    ];
                    if ($i > 0) {
                        $node['parent'] = $path[$i -1];
                    // $node['id'] = array_slice($path, 0, $i - 1);
                    } else {
                        // $node['id'] = $path[$i];
                    }
                    $configurator[] = $node;
                }
            }
            $device = [
                'id' => $attributes['Ref'],
                'address' => $attributes['Address_hex'],
                'name' => $path[count($path) - 1],
                'parent' => $path[count($path) - 2],
                'type' => $attributes['Type'],
                'instanceID' => 0,
            ];
            $supported = array_key_exists($attributes['Type'], self::GUID_MAP);
            switch ($type) {
                case 'Sensor':
                    if ($supported) {
                        $device['create'] = [
                            'moduleID' => self::GUID_MAP[$attributes['Type']],
                            'location' => array_slice($path, 0, count($path) - 1),
                            'configuration' => [
                                'DeviceID' => $attributes['Address_hex'],
                            ],
                        ];
                    }
                    break;

                case 'Actuator':
                    if ($supported) {
                        $create =
                            [
                                'moduleID' => self::GUID_MAP[$attributes['Type']],
                                'location' => array_slice($path, 0, count($path) - 1),
                                'configuration' => [
                                    'DeviceID' => $attributes['Address'],
                                ],
                            ];
                        if (array_key_exists('SensorAddr_hex', $attributes)) {
                            $create['configuration']['ReturnID'] =$attributes['SensorAddr_hex'];
                        }
                        $device['create'] = $create;
                    }
                    break;

                default:
                    break;
            }

            $configurator[] = $device;
        }

        private function hasID($id, $values)
        {
            foreach ($values as $element) {
                if ($element['id'] == $id) {
                    return true;
                }
            }
            return false;
        }
    }
