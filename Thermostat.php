<?php
/**
 * @author Kevin Thackorie
 */

require('Watcher.php');

/**
 * The thermostat is the interface between this application and the real thermostat via its web interface
 */
class Thermostat
{
        private $domain          = 'https://controlyourthermostat.ca/';
        private $authenticateUri = 'servlet/LoginController';
        private $adjusterUri     = 'spring/stars/consumer/thermostat/manual';
        private $username        = '12345678912345';
        private $password        = 'abcdef';
        private $thermostatIds   = '[12345]';
        private $cookie          = null;

        /**
         * Change the hold setting of the real thermostat
         *
         * @param array $newSetting 
         *
         * @return boolean
         */
        public function changeHoldSetting($newSetting)
        {
            $newHoldTemperature = number_format($newSetting['temperature'], 1);
            $mode               = $newSetting['mode'];

            if ($this->cookie === null) {
                    $this->authenticate();        
            }

            $this->adjustHoldTemperature($newHoldTemperature, $mode);

            return true;
        }

        /**
         * Perform the actual adjustment
         *
         * @param string The new hold temperature in degrees celsius (e.g. 20.5)
         * @param string The theromostat mode (i.e. COOL, HEAT, or OFF)
         *
         * @throws Exception
         */
        private function adjustHoldTemperature($newHoldTemperature, $mode)
        {
            $watcher    = new Watcher();
            $modeList   = $watcher->getModes();
            $postFields = "accountId=&thermostatIds={$this->thermostatIds}&fan=AUTO&temperature={$newHoldTemperature}&temperatureUnit=C&mode={$mode}&hold=true";

            $this->sendRequest($postFields);

            if ($mode === $modeList['off']) {
                // HVAC system needs to be turned off. The request has been sent once already, but send it again after 8 seconds to be sure.
                sleep(8);

                $this->sendRequest($postFields);
            }
        }

        private function sendRequest($postFields)
        {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_URL, $this->domain . $this->adjusterUri);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            // curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'curlAdjustResponseHeaderCallback'));

            $result = curl_exec($ch);
            
            if ($result === false) {
                    $errorMessage = curl_error($ch) . '  |  cURL Error Number: ' . curl_errno($ch);

                    throw new Exception($errorMessage);
            }

            curl_close($ch);
        }

        /**
         * Authenticate with the real thermostat's web interface to get a session cookie
         *
         * @throws Exception
         */
        private function authenticate()
        {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_URL, $this->domain . $this->authenticateUri);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "USERNAME={$this->username}&PASSWORD={$this->password}&ACTION=LOGIN&login=Submit");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'curlAuthenticateResponseHeaderCallback'));

            $result = curl_exec($ch);
            
            if ($result === false) {
                    $errorMessage = curl_error($ch) . '  |  cURL Error Number: ' . curl_errno($ch);

                    throw new Exception($errorMessage);
            }

            curl_close($ch);
        }

        /**
         * cURL callback to examine headers
         *
         * @param object $ch cURL handle
         * @param string $headerLine An individual header from the response
         *
         * @return string
         */
        private function curlAuthenticateResponseHeaderCallback($ch, $headerLine) {
            if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $headerLine, $cookie) == 1) {
                $this->cookie = $cookie[1];
            }

            return strlen($headerLine);
        }

        // private function curlAdjustResponseHeaderCallback($ch, $headerLine) {
        //     echo 'Header line: ' . PHP_EOL;
        //     var_dump($headerLine);
        //     echo PHP_EOL;

        //     return strlen($headerLine);
        // }
}