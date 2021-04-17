<?php
/**
 * Raspi Thermostat
 *
 * A PHP application for remotely adjusting your thermostat based on input from a USB connected thermometer.
 * This application's logic assumes that the thermostat is not being altered by anyone or anything else.
 *
 * @TODO Turn heat on only once below lower margin, but don't turn it off until median is reached.
 *       Turn cool on only once above upper margin, but don't turn it off until median is reached.
 * @TODO Change flat file to a database so that number of runs can also be saved and used to switch mode of HVAC
 *       (e.g heating to cooling) if the temperature goes in the wrong direction after 2 or 3 runs.
 * @TODO Add awareness of the real thermostat's web session TTL so that authentication is not performed if the session cookie is still valid.
 * @TODO Add seasonal intelligence via config files so that the desired ranges become dynamic depending on seasons.
 *
 * @author Kevin Thackorie
 */

require_once('Thermostat.php');
require_once('Watcher.php');

define('LOGS_DIRECTORY', '/var/www/sentinel-client/logs/');
define('FLAT_FILES_DIRECTORY', '/var/www/sentinel-client/flat_files/');
define('CALIBRATION_OFFSET', -1.56); // The average difference between the BIOS sensor used for the past year and the temperv14 USB sensor

try {
        $roomTemperature = (float)@shell_exec('sudo temperv14 -c');

        if ($roomTemperature == 0) {
                // reading the room temperature from the USB sensor failed. Wait 10 seconds and try again.
                sleep(10);

                $roomTemperature = (float)@shell_exec('sudo temperv14 -c');

                if ($roomTemperature == 0) {
                        logError('There was a problem getting the current temperature from the sensor even after a retry 10 seconds later');

                        exit();
                }
        }

        $roomTemperature = $roomTemperature + CALIBRATION_OFFSET;
        $thermostat      = new Thermostat();  
        $watcher         = new Watcher();

        if (file_exists(FLAT_FILES_DIRECTORY . 'currentHoldTemperature')) {
            $currentHoldTemperature = file_get_contents(FLAT_FILES_DIRECTORY . 'currentHoldTemperature');
        } else {
            $currentHoldTemperature = false;
        }

        if ($currentHoldTemperature === false) {
                /* we don't know what the current hold temperature of the real thermostat is, likely because this
                 * is the first time this application is being run. Therefore, arbitrarily set the hold setting
                 * of the real thermostat to the middle of the ideal range. */
                $medianOfTargetRange = $watcher->getMedianOfTargetRange();
                $thermostatModes     = $watcher->getModes();
                $targetMode          = $watcher->determineMode($roomTemperature);

                if ($targetMode === $thermostatModes['off']) {
                    $initialTemperature = $medianOfTargetRange;
                }

                if ($targetMode === $thermostatModes['heat']) {
                    $initialTemperature = $medianOfTargetRange + $watcher->getIncrement();
                }

                if ($targetMode === $thermostatModes['cool']) {
                    $initialTemperature = $medianOfTargetRange - $watcher->getIncrement();
                }

                $initialSetting = array(
                    'temperature' => $initialTemperature,
                    'mode'        => $targetMode,
                );

                changeThermostat($thermostat, $initialSetting);

                $activityMessage = 
                    'INITIAL SETTING... Room Temperature: ' . $roomTemperature . 
                    '... Thermostat hold setting set to: ' . $initialSetting['temperature'] . '|' . $initialSetting['mode'];

                logActivity($activityMessage);

                exit();
        }

        $newSetting = $watcher->determineNewSetting($currentHoldTemperature, $roomTemperature);

        if ($newSetting === false) {
                $currentMode = $watcher->getModeOfLastAdjustment();

                if ($currentMode === false) {
                    $currentMode = 'Current mode unknown';
                }

                $activityMessage = 
                    'Room Temperature: ' . $roomTemperature . 
                    ' Current Hold: ' . $currentHoldTemperature . '|' . $currentMode .
                    '... No change is necessary';

                logActivity($activityMessage);

                exit();
        }

        changeThermostat($thermostat, $newSetting);

        $activityMessage = 
            'Room Temperature: ' . $roomTemperature . ' ' .
            'Current Hold Temperature: ' . $currentHoldTemperature . ' ' .
            '... Thermostat hold setting changed to: ' . $newSetting['temperature'] . '|' . $newSetting['mode'];

        logActivity($activityMessage);
} catch (Exception $exception) {
        $message = $exception->getMessage();

        logError($message);
}

/**
 * Change the thermostat.
 *
 * @param object $thermostat
 * @param array  $newSetting
 */
function changeThermostat($thermostat, $newSetting)
{
        $newSetting['temperature'] = $newSetting['temperature'];
        $result                    = $thermostat->changeHoldSetting($newSetting);

        if ($result !== true) {
                $message = "There was a problem changing your thermostat's hold setting.";

                logError($message);

                exit();
        }

        // record data in flat files for reference in the next run
        file_put_contents(FLAT_FILES_DIRECTORY . 'currentHoldTemperature', $newSetting['temperature']);
        file_put_contents(FLAT_FILES_DIRECTORY . 'modeOfLastAdjustment', $newSetting['mode']);
        file_put_contents(FLAT_FILES_DIRECTORY . 'timeOfLastAdjustment', time());

        return $newSetting['temperature'];
}

/**
 * Log the error that just occurred.
 *
 * @param string $message
 */
function logError($message)
{
        $dateTime = date('F jS, Y H:i:s');
        $logFile  = LOGS_DIRECTORY . 'error.log';

        file_put_contents($logFile, PHP_EOL . PHP_EOL . $dateTime . ': ' . $message, FILE_APPEND);
}

/**
 * Log the action that was just taken.
 *
 * @param string $message
 */
function logActivity($message)
{
        $dateTime = date('F jS, Y H:i:s');
        $logFile  = LOGS_DIRECTORY . 'activity.log';

        file_put_contents(
            $logFile, PHP_EOL . PHP_EOL . $dateTime . '| ' . $message, FILE_APPEND
        );
}
