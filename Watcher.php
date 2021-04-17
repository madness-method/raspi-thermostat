<?php
/**
 * @author Kevin Thackorie
 */

/**
 * The watcher plays the role of the human monitoring the current room temperature and deciding
 * what changes should be made, if any, to the thermostat's hold setting.
 */
class Watcher
{
    /**
     * @var integer Degrees celsius to bump up or down the thermostat's hold setting to trigger heating or cooling
     */
    const INCREMENT = 2.0;

    /**
     * @var float Degrees celsius +/- from the median of the target range within which no action is required
     */
    const TOLERABLE_DEVIANCE = 0.35;

    /**
     * @var float Temperature in degrees celsius considered to be the lower limit of the ideal range
     */
    const DESIRED_MINIMUM = 19.80;

    /**
     * @var float Temperature in degrees celsius considered to be the upper limit of the ideal range
     */
    const DESIRED_MAXIMUM = 20.50;

    /**
     * @var integer Hour of the day considered to be the start of the exception period
     */
    const EXCEPTION_PERIOD_START = 18;

    /**
     * @var integer Hour of the day considered to be the end of the exception period
     */
    const EXCEPTION_PERIOD_END = 19;

    /**
     * @var float Temperature in degrees celsius considered to be the lower limit of the ideal range
     */
    const EXCEPTION_DESIRED_MINIMUM = 19.80;

    /**
     * @var float Temperature in degrees celsius considered to be the upper limit of the ideal range
     */
    const EXCEPTION_DESIRED_MAXIMUM = 20.30;

    /**
     * @var integer Minutes to give the furnace to impact the temperature of the room
     */
    const TIME_FOR_EFFECT_HEAT = 10;

    /**
     * @var integer Minutes to give the air conditioner to impact the temperature of the room
     */
    const TIME_FOR_EFFECT_COOL = 15;

    /**
     * @var string The thermostat mode for air conditioning
     */
    const MODE_COOL = 'COOL';

    /**
     * @var string The thermostat mode for heat
     */
    const MODE_HEAT = 'HEAT';

    /**
     * @var string The thermostat mode for off
     */
    const MODE_OFF = 'OFF';

    /**
     * @var string The directory in which the flat files serving as the db live
     */
    const FLAT_FILES_DIRECTORY = '/var/www/sentinel-client/flat_files/';

    /**
     * Determine the temperature to which the thermostat should be set
     *
     * @param string  $currentHoldTemperature The current hold setting of the real thermostat in degrees celsius (e.g. '21.5')
     * @param float   $roomTemperature        The current temperature of the room in degrees celsius (e.g. '20.93')
     *
     * @return array|false
     */
    public function determineNewSetting($currentHoldTemperature, $roomTemperature)
    {
        $mode                   = $this->determineMode($roomTemperature);
        $currentHoldTemperature = (int) $currentHoldTemperature;

        if ($mode === self::MODE_OFF) {
            $newHoldSetting = $this->buildOffSetting($currentHoldTemperature);
        }

        if ($mode === self::MODE_HEAT) {
            $newHoldSetting = $this->buildHeatSetting($currentHoldTemperature);
        }

        if ($mode === self::MODE_COOL) {
            $newHoldSetting = $this->buildCoolSetting($currentHoldTemperature);
        }

        return $newHoldSetting;
    }

    /**
     * Determine the mode to which the thermostat needs to be set.
     *
     * @param float $roomTemperature The current temperature of the room
     *
     * @return string
     */
    public function determineMode($roomTemperature)
    {
        $median               = $this->getMedianOfTargetRange();
        $modeOfLastAdjustment = $this->getModeOfLastAdjustment();
        $hvacIsOn             = ($modeOfLastAdjustment !== self::MODE_OFF || $modeOfLastAdjustment === false) ? true : false;

        if ($hvacIsOn) {
            /* the HVAC is on, and we only want it to stop if the room temperature has hit
            the median of the range or passed it in the appropriate direction */
            if ($modeOfLastAdjustment === self::MODE_HEAT) {
                if ($roomTemperature >= $median) {
                    return self::MODE_OFF;
                } else {
                    return self::MODE_HEAT;
                }
            }

            if ($modeOfLastAdjustment === self::MODE_COOL) {
                if ($roomTemperature <= $median) {
                    return self::MODE_OFF;
                } else {
                    return self::MODE_COOL;
                }
            }
        }

        /* the HVAC is off, and we want to let the room temperature sway organically 
        within the tolerable range so as to avoid frequent HVAC activation */
        $lowerTolerance = $median - self::TOLERABLE_DEVIANCE;
        $upperTolerance = $median + self::TOLERABLE_DEVIANCE;

        if ($roomTemperature >= $lowerTolerance && $roomTemperature <= $upperTolerance) {
            return self::MODE_OFF;
        }

        if ($roomTemperature < $lowerTolerance) {
            return self::MODE_HEAT;
        }

        if ($roomTemperature > $upperTolerance) {
            return self::MODE_COOL;
        }
    }

    /**
     * Calculate the median of the desired temperature range.
     *
     * @return string
     */
    public function getMedianOfTargetRange()
    {
        $limits     = $this->determineLimits();
        $lowerLimit = $limits['lowerLimit'];
        $upperLimit = $limits['upperLimit'];

        return (string)($lowerLimit + $upperLimit) / 2;
    }

    /**
     * Return the mode constants.
     *
     * @return array
     */
    public function getModes()
    {
        return array(
            'off'  => self::MODE_OFF,
            'heat' => self::MODE_HEAT,
            'cool' => self::MODE_COOL,
        );
    }

    /**
     * Return the increment.
     *
     * @return integer
     */
    public function getIncrement()
    {
        return self::INCREMENT;
    }

    /**
     * Get the mode of the last thermostat adjustment.
     *
     * @return string|false
     */
    public function getModeOfLastAdjustment()
    {
        if (file_exists(self::FLAT_FILES_DIRECTORY . 'modeOfLastAdjustment') === false) {
            return false;
        }

        return file_get_contents(self::FLAT_FILES_DIRECTORY . 'modeOfLastAdjustment');
    }

    /**
     * Set the lower and upper limits of the desired temperature range. This is only necessary to
     * support exception periods.
     *
     * @return array
     */
    private function determineLimits()
    {
        $currentHour = date('G');

        if ($currentHour >= self::EXCEPTION_PERIOD_START && $currentHour < self::EXCEPTION_PERIOD_END) {
            $lowerLimit = self::EXCEPTION_DESIRED_MINIMUM;
            $upperLimit = self::EXCEPTION_DESIRED_MAXIMUM;
        } else {
            $lowerLimit = self::DESIRED_MINIMUM;
            $upperLimit = self::DESIRED_MAXIMUM;
        }

        return array(
            'lowerLimit' => $lowerLimit,
            'upperLimit' => $upperLimit,
        );
    }

    /**
     * Build the appropriate off setting.
     *
     * @param integer $currentHoldTemperature
     *
     * @return array|false
     */
    private function buildOffSetting($currentHoldTemperature)
    {
        $modeOfLastAdjustment = $this->getModeOfLastAdjustment();

        if ($modeOfLastAdjustment === false) {
            return array(
                'mode'        => self::MODE_OFF,
                'temperature' => $currentHoldTemperature,
            );
        }

        if ($modeOfLastAdjustment === self::MODE_HEAT) {
            return array(
                'mode'        => self::MODE_OFF,
                'temperature' => $currentHoldTemperature - self::INCREMENT,
            );
        }

        if ($modeOfLastAdjustment === self::MODE_COOL) {
            return array(
                'mode'        => self::MODE_OFF,
                'temperature' => $currentHoldTemperature + self::INCREMENT,
            );
        }

        return false;
    }

    /**
     * Build the appropriate heat setting.
     *
     * @param integer $currentHoldTemperature
     *
     * @return array|false
     */
    private function buildHeatSetting($currentHoldTemperature)
    {
        $modeOfLastAdjustment       = $this->getModeOfLastAdjustment();
        $minutesSinceLastAdjustment = $this->getMinutesSinceLastAdjustment();

        if ($modeOfLastAdjustment !== false) {
            if ($modeOfLastAdjustment === self::MODE_HEAT && $minutesSinceLastAdjustment < self::TIME_FOR_EFFECT_HEAT) {
                return false;
            }
        }

        $newHoldSetting['mode']        = self::MODE_HEAT;
        $newHoldSetting['temperature'] = $currentHoldTemperature + self::INCREMENT;

        return $newHoldSetting;
    }

    /**
     * Build the appropriate cool setting.
     *
     * @param integer $currentHoldTemperature
     *
     * @return array|false
     */
    private function buildCoolSetting($currentHoldTemperature)
    {
        $modeOfLastAdjustment       = $this->getModeOfLastAdjustment();
        $minutesSinceLastAdjustment = $this->getMinutesSinceLastAdjustment();

        if ($modeOfLastAdjustment !== false) {
            if ($modeOfLastAdjustment === self::MODE_COOL && $minutesSinceLastAdjustment < self::TIME_FOR_EFFECT_COOL) {
                return false;
            }
        }

        $newHoldSetting['mode']        = self::MODE_COOL;
        $newHoldSetting['temperature'] = $currentHoldTemperature - self::INCREMENT;

        return $newHoldSetting;
    }

    /**
     * Figure out how long it has been since an actual change to the thermostat's setting was made.
     *
     * @return integer (minutes since last adjustment)
     */
    private function getMinutesSinceLastAdjustment()
    {
        if (file_exists(self::FLAT_FILES_DIRECTORY . 'timeOfLastAdjustment') === false) {
            return 999;
        }

        $timestampOfLastAdjustment = file_get_contents(self::FLAT_FILES_DIRECTORY . 'timeOfLastAdjustment');

        if ($timestampOfLastAdjustment === false) {
            return 999;
        }

        return (time() - $timestampOfLastAdjustment) / 60;
    }
}
