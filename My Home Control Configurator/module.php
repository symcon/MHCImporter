<?php

declare(strict_types=1);
    class myHomeControlConfigurator extends IPSModule
    {
        public const GUID_MAP = [
            'PTMSwitchModule' => '{40C99CC9-EC04-49C8-BB9B-73E21B6FA265}',
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

            if ($this->ReadPropertyString('ImportFile') != '') {
                $this->UIImport($this->ReadPropertyString('ImportFile'));
            }
        }

        public function GetConfigurationForm(): string
        {
            $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            if (($this->ReadPropertyString('ImportFile') != '')) {
                $data['actions'][0]['values'] = $this->createConfiguratorValues($this->ReadPropertyString('ImportFile'));
            }
            return json_encode($data);
        }

        private function createConfiguratorValues(String $File)
        {
            if (strlen($File) == 0) {
                return false;
            }
            $xml = simplexml_load_string(base64_decode($File), null, LIBXML_NOCDATA);
            $array = json_decode(json_encode($xml), true);
            if (!array_key_exists('DeviceAddresses', $array)) {
                return [];
            }

            $array = json_decode(json_encode($xml), true);
            $configurator = [];
            $id = 1;
            foreach ($array['DeviceAddresses']['Sensor'] as $sensor) {
                $this->createDevice($configurator, $sensor['@attributes'], 'Sensor', $id);
            }
            foreach ($array['DeviceAddresses']['Actuator'] as $actuator) {
                $this->createDevice($configurator, $actuator['@attributes'], 'Actuator', $id);
            }
            return $configurator;
        }

        public function UIImport($File)
        {
            $this->UpdateFormField('Configurator', 'values', json_encode($this->createConfiguratorValues($File)));
        }

        private function createId($path, &$configurator, &$id)
        {
            $pathId =  $this->getID($path, $configurator);
            if ($pathId != 0) {
                return $pathId;
            } elseif (count($path) == 1) {
                $configurator[] = [
                    'name' => $path[0],
                    'id' => ++$id,
                    'parent' => 0,
                ];
                return $id;
            } else {
                $parentId = $this->createId(array_slice($path, 0, count($path) - 1), $configurator, $id);
                $configurator[] = [
                    'name' => $path[count($path) - 1],
                    'id' => ++$id,
                    'parent' => $parentId,
                ];
                return $id;
            }
        }


        private function createDevice(&$configurator, $attributes, String $type, &$id)
        {
            $parenthesisCount = 0;
            $pathStr = $attributes['Ref'];
            $slashIndices = [0];
            for ($i=1; $i < strlen($pathStr) - 1; $i++) {
                $char = $pathStr[$i];
                if ($char == '(') {
                    $parenthesisCount++;
                } elseif ($char == ')') {
                    $parenthesisCount--;
                } elseif ($char == '/' && $parenthesisCount == 0 && $pathStr[$i - 1] != '/' && $pathStr[$i +1] != '/') {
                    $slashIndices[] = $i;
                }
            }
            $slashIndices[] = strlen($pathStr);
            $path = [];
            for ($i=0; $i < count($slashIndices) - 1 ; $i++) {
                $length = $slashIndices[$i + 1] - $slashIndices[$i];
                if ($i != 0) {
                    $length--;
                }
                $path[] = substr($pathStr, $i == 0 ? $slashIndices[$i] : $slashIndices[$i] + 1, $length);
            }
            $parentId = $this->createId(array_slice($path, 0, count($path) - 1), $configurator, $id);
            $device = [
                'address' => $attributes['Address_hex'],
                'name' => $path[count($path) - 1],
                'parent' => $parentId,
                'id' => ++$id,
                'type' => $attributes['Type'],
                'instanceID' => 0,
            ];
            if (isset($attributes['FullAddress_hex'])) {
                $device['baseID'] = dechex(hexdec($attributes['FullAddress_hex']) & 0xFFFFFF80);
            }
            $supported = array_key_exists($attributes['Type'], self::GUID_MAP);
            switch ($type) {
                case 'Sensor':
                    if ($supported) {
                        $guid = self::GUID_MAP[$attributes['Type']];
                        $device['create'] = [
                            [
                                'moduleID' => $guid,
                                'location' => array_slice($path, 0, count($path) - 1),
                                'configuration' => [
                                    'DeviceID' => $attributes['Address_hex'],
                                ],
                            ]
                        ];
                        $device['instanceID'] = $this->searchDevice($attributes['Address_hex'], $guid);
                    }
                    break;

                    case 'Actuator':
                        if ($supported) {
                            $guid = self::GUID_MAP[$attributes['Type']];
                            $create =
                            [
                                'moduleID' => $guid,
                                'location' => array_slice($path, 0, count($path) - 1),
                                'configuration' => [
                                    'DeviceID' => intval($attributes['Address']),
                                    'Mode'     => 1, // Only required for Eltako Switch
                                ],
                            ];
                            $device['instanceID'] = $this->searchDevice(intval($attributes['Address']), $guid);
                            if (array_key_exists('SensorAddr_hex', $attributes)) {
                                $create[0]['configuration']['ReturnID'] = $attributes['SensorAddr_hex'];
                            }
                            // Override and make a nicer name
                            $device['address'] = sprintf("%s (%d)", $attributes['Address_hex'], intval($attributes['Address']));
                            $device['create'] = $create;
                        }
                    break;

                default:
                    break;
            }
            if (isset($device['create'])) {
                $fgw14 = isset($device['baseID']) && (hexdec($device['baseID']) & 0x00FFFFFF) === 0;
                $gateway = [
                        'name' => $fgw14 ? 'FGW14 Gateway' : 'LAN Gateway',
                        'moduleID' => '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}', // EnOcean Gateway;
                        'configuration' => [
                            'GatewayMode' => $fgw14 ? 4 : 3 // LAN Gateway
                        ]
                    ];
                if ($fgw14) {
                    $gateway['configuration']['BaseID'] = $device['baseID'];
                }
                $device['create'][] = $gateway;
                $this->SendDebug('Create', json_encode($device['create']), 0);
            }

            $configurator[] = $device;
        }

        private function getID($path, $values)
        {
            $getID = function ($parent, $name) use ($values) {
                foreach ($values as $element) {
                    if ($element['parent'] == $parent && $element['name'] == $name) {
                        return $element['id'];
                    }
                }
                return 0;
            };
            $id = 0;
            foreach ($path as $name) {
                $id = $getID($id, $name);
            }
            return $id;
        }

        private function searchDevice($deviceID, $guid): int
        {
            $ids = IPS_GetInstanceListByModuleID($guid);
            foreach ($ids as $id) {
                if (IPS_GetProperty($id, 'DeviceID') == $deviceID) {
                    return $id;
                }
            }
            return 0;
        }
    }
