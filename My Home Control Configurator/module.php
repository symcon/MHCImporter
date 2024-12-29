<?php

declare(strict_types=1);
    class myHomeControlConfigurator extends IPSModule
    {
        public const GUID_MAP = [
            'PTMSwitchModule' => '{40C99CC9-EC04-49C8-BB9B-73E21B6FA265}',
            'PTMSwitchModule-5' => '{40C99CC9-EC04-49C8-BB9B-73E21B6FA265}',
            'Switch_1' => '{FD46DA33-724B-489E-A931-C00BFD0166C9}',
            'Switch_1-5' => '{FD46DA33-724B-489E-A931-C00BFD0166C9}',
            'Switch_1-7' => '{FD46DA33-724B-489E-A931-C00BFD0166C9}',
            'Dimmer_1' => '{48909406-A2B9-4990-934F-28B9A80CD079}',
            'Dimmer_1-7' => '{48909406-A2B9-4990-934F-28B9A80CD079}',
            'Jalousie_1' =>  '{1463CAE7-C7D5-4623-8539-DD7ADA6E92A9}',
            'WindowContact' => '{432FF87E-4497-48D6-8ED9-EE7104D50001}',
            'WindowContact-6' => '{432FF87E-4497-48D6-8ED9-EE7104D50001}',
            'RoomTemperatureControl' => '{432FF87E-4497-48D6-8ED9-EE7104A51003}',
            'TemperatureHumidity' => '{432FF87E-4497-48D6-8ED9-EE7104A50402}',
            'TemperatureHumidity-7' => '{432FF87E-4497-48D6-8ED9-EE7104A50402}',
            'PIR-5' => '{432FF87E-4497-48D6-8ED9-EE7104F60201}',
            'PIR-7' => '{432FF87E-4497-48D6-8ED9-EE7104A50703}',
            'WindowHandle' => '{1C8D7E80-3ED1-4117-BB53-9C5F61B1BEF3}',
            'Brightness' => '{AF827EB8-08A3-434D-9690-424AFF06C698}',
            'Brightness-7' => '{AF827EB8-08A3-434D-9690-424AFF06C698}',
            'EnergyMeter' => '{432FF87E-4497-48D6-8ED9-EE7104A51201}',
            'EnergyMeter-7' => '{432FF87E-4497-48D6-8ED9-EE7104A51201}',
            'SmokeAlarm' => '{432FF87E-4497-48D6-8ED9-EE7104A53003}',
            'SmokeAlarm-7' => '{432FF87E-4497-48D6-8ED9-EE7104A53003}',
            'Valve_1' => '{7C25F5A6-ED34-4FB4-8A6D-D49DFE636CDC}',
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
                return [];
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

            $getBaseIDByParentID = function ($parentID) use ($configurator) {
                foreach ($configurator as $otherDevice) {
                    if (isset($otherDevice['create'])) {
                        if ($otherDevice['parent'] == $parentID && isset($otherDevice['baseID'])) {
                            return $otherDevice['baseID'];
                        }
                    }
                }
                return false;
            };

            // Try fixing missing baseIDs
            foreach ($configurator as &$device) {
                if (isset($device['address']) && !isset($device['baseID'])) {
                    $parentID = $device['parent'];
                    $baseID = false;
                    while ($parentID != 0 && $baseID === false) {
                        $baseID = $getBaseIDByParentID($parentID);
                        $parentID = array_search($parentID, array_column($configurator, 'id'));
                    }
                    if ($baseID !== false) {
                        $device['baseID'] = $baseID;
                        if (isset($device['create'])) {
                            array_pop($device['create']);
                            $this->addGateway($device);
                        }
                    }
                }
            }
            return $configurator;
        }

        private function addGateway(&$device)
        {
            if (isset($device['create'])) {
                $fgw14 = isset($device['baseID']) && (hexdec($device['baseID']) & 0x0000FFFF) === 0;
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

            // Fixup SensorAddr_hex and Address_hex of the length is 9 octets
            // We may want to save this one byte for further use. 5 may mean F6, 6 may mean D5 and 7 may mean A5 as EEP main type
            $subtype = "";
            if (isset($attributes['Address_hex']) && strlen($attributes['Address_hex']) == 9) {
                $subtype = substr($attributes['Address_hex'], 0, 1);
                $attributes['Address_hex'] = substr($attributes['Address_hex'], 1);
            }
            if (isset($attributes['SensorAddr_hex']) && strlen($attributes['SensorAddr_hex']) == 9) {
                $subtype = substr($attributes['SensorAddr_hex'], 0, 1);
                $attributes['SensorAddr_hex'] = substr($attributes['SensorAddr_hex'], 1);
            }

            $subtypeToString = function($subtype) {
                switch ($subtype) {
                    case '5':
                        return 'F6-XX-XX';
                    case '6':
                        return 'D5-XX-XX';
                    case '7':
                        return 'A5-XX-XX';
                    default:
                        return $subtype;
                }
            };

            $device = [
                'address' => $attributes['Address_hex'],
                'name' => $path[count($path) - 1],
                'parent' => $parentId,
                'id' => ++$id,
                'type' => $subtype ? sprintf("%s (%s)", $attributes['Type'], $subtypeToString($subtype)) : $attributes['Type'],
                'instanceID' => 0,
            ];
            if (isset($attributes['FullAddress_hex'])) {
                $device['baseID'] = strtoupper(dechex(hexdec($attributes['FullAddress_hex']) & 0xFFFFFF80));
            }
            $typename = $subtype ? sprintf("%s-%s", $attributes['Type'], $subtype) : $attributes['Type'];
            $supported = array_key_exists($typename, self::GUID_MAP);
            switch ($type) {
                case 'Sensor':
                    if ($supported) {
                        $guid = self::GUID_MAP[$typename];
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
                        $guid = self::GUID_MAP[$typename];
                        $create =
                        [
                            [
                                'moduleID' => $guid,
                                'location' => array_slice($path, 0, count($path) - 1),
                                'configuration' => [
                                    'DeviceID' => intval($attributes['Address']),
                                ],
                            ]
                        ];
                        // FIXME: We may only need to set this if $subtype is 7
                        if ($guid == '{FD46DA33-724B-489E-A931-C00BFD0166C9}' /*Eltako Switch*/) {
                            $create[0]['configuration']['Mode'] = 1;
                        }
                        $device['instanceID'] = $this->searchDevice(intval($attributes['Address']), $guid);
                        if (array_key_exists('SensorAddr_hex', $attributes)) {
                            $returnID = $attributes['SensorAddr_hex'];
                            if (substr($attributes['SensorAddr_hex'], 0, 4) == substr($attributes['FullAddress_hex'], 0, 4)) {
                                // We only want to use the last 2 characters from SensorAddr_hex
                                // EF0010XX -> 000000XX
                                $returnID = '000000' . substr($returnID, strlen($returnID) - 2, 4);
                            }
                            $create[0]['configuration']['ReturnID'] = $returnID;
                        }
                        $device['create'] = $create;
                    }
                    // Override and make a nicer name - even for unsupported ones
                    $device['address'] = sprintf("%s (%d)", $attributes['Address_hex'], intval($attributes['Address']));
                    break;

                default:
                    break;
            }
            $this->addGateway($device);

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
